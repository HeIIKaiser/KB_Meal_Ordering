<?php

namespace App\Filament\Resources\MealGroups;

use App\Domain\Ordering\OrderingException;
use App\Domain\Tickets\MealGroupService;
use App\Domain\Tickets\RepriceResult;
use App\Enums\NavGroup;
use App\Enums\PricingMode;
use App\Filament\Resources\MealGroups\Pages\ManageMealGroups;
use App\Models\EmployeeGroupMembership;
use App\Models\MealGroup;
use App\Models\MealGroupMode;
use App\Support\LocalTime;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Skupiny stravníkov: pricing mode (daily | tickets) with a dated history, default tickets. */
class MealGroupResource extends Resource
{
    protected static ?string $model = MealGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::People;

    protected static ?int $navigationSort = 15;

    protected static ?string $modelLabel = 'skupina stravníkov';

    protected static ?string $pluralModelLabel = 'skupiny stravníkov';

    protected static ?string $navigationLabel = 'Skupiny stravníkov';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Názov')->placeholder('napr. 12 h')->required()->maxLength(60)->unique(ignoreRecord: true),
            TextInput::make('default_tickets')
                ->label('Lístkov na mesiac (predvolené)')
                ->helperText('Použije sa, kým na stránke Lístky na mesiac nenastavíte počet pre konkrétny mesiac. Pri dennom režime sa nepoužíva.')
                ->integer()->minValue(0)->maxValue(999)->default(0)->required(),
            Toggle::make('is_active')->label('Aktívna')->helperText('Neaktívna skupina sa neponúka pri zamestnancoch.')->default(true),
            Select::make('initial_mode')
                ->label('Režim')
                ->options(collect(PricingMode::cases())->mapWithKeys(fn ($m) => [$m->value => $m->label()]))
                ->default(PricingMode::Daily->value)
                ->required()
                ->visibleOn('create')
                ->helperText('Neskôr sa mení akciou „Zmeniť režim“ s dátumom platnosti.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('modes'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Skupina')->searchable()->sortable(),
                TextColumn::make('mode')
                    ->label('Režim dnes')
                    ->state(fn (MealGroup $record) => $record->modeOn(LocalTime::today())->label())
                    ->badge()
                    ->color(fn (MealGroup $record) => $record->modeOn(LocalTime::today()) === PricingMode::Tickets ? 'warning' : 'gray')
                    ->description(fn (MealGroup $record) => static::modeHistory($record)),
                TextColumn::make('default_tickets')->label('Lístkov / mesiac')->numeric(),
                TextColumn::make('members')
                    ->label('Zamestnancov dnes')
                    ->state(fn (MealGroup $record) => static::membersToday($record)),
                IconColumn::make('is_active')->label('Aktívna')->boolean(),
            ])
            ->recordActions([
                static::switchModeAction(),
                EditAction::make(),
                DeleteAction::make()->modalDescription('Skupinu možno zmazať len ak v nej nikdy nebol žiadny zamestnanec.'),
            ]);
    }

    public static function switchModeAction(): Action
    {
        return Action::make('switchMode')
            ->label('Zmeniť režim')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->authorize(fn () => auth()->user()?->isAdmin())
            ->modalHeading(fn (MealGroup $record) => 'Zmeniť režim skupiny '.$record->name)
            ->modalDescription('Platí pre jedlá s dátumom od zvoleného dňa. Staršie jedlá si ponechajú svoje ceny. Už objednané jedlá od toho dňa sa prepočítajú podľa nového režimu.')
            ->schema([
                Select::make('mode')
                    ->label('Nový režim')
                    ->options(collect(PricingMode::cases())->mapWithKeys(fn ($m) => [$m->value => $m->label()]))
                    ->required()
                    ->live(),
                DatePicker::make('effective_from')
                    ->label('Platí od')
                    ->native(false)->displayFormat('j. n. Y')
                    ->default(fn () => LocalTime::today()->toDateString())
                    ->required()
                    ->live(),
                Placeholder::make('preview')
                    ->label('Dopad na objednané jedlá')
                    ->content(fn (Get $get, MealGroup $record) => static::previewText($record, $get('mode'), $get('effective_from'))),
            ])
            ->action(function (MealGroup $record, array $data, Action $action) {
                try {
                    $result = app(MealGroupService::class)->switchMode($record, PricingMode::from($data['mode']), $data['effective_from'], auth()->user());
                } catch (OrderingException $e) {
                    Notification::make()->title('Nedá sa')->body($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }
                Notification::make()->title('Režim zmenený')->body(static::describe($result))->success()->send();
            });
    }

    public static function previewText(MealGroup $group, ?string $mode, ?string $from): HtmlString|string
    {
        if (! $mode || ! $from) {
            return '–';
        }
        try {
            $result = app(MealGroupService::class)->previewSwitch($group, PricingMode::from($mode), $from, auth()->user());
        } catch (OrderingException $e) {
            return new HtmlString('<span class="text-danger-600 font-semibold">'.e($e->getMessage()).'</span>');
        }

        return static::describe($result);
    }

    /** "Prepočíta sa 12 objednávok u 3 zamestnancov, rozdiel −45,00 €." */
    public static function describe(RepriceResult $result): string
    {
        if ($result->isEmpty()) {
            return 'Žiadna objednaná cena sa nezmení.';
        }
        $diff = $result->centsDiff();

        $n = $result->orders();
        $verb = $n >= 2 && $n <= 4 ? 'Prepočítajú sa ' : 'Prepočíta sa ';
        $orders = $n.' '.($n === 1 ? 'objednávka' : ($n <= 4 ? 'objednávky' : 'objednávok'));
        $people = $result->employees() === 1 ? '1 zamestnanca' : $result->employees().' zamestnancov';

        return $verb.$orders.' u '.$people.': '
            .Money::format($result->centsBefore()).' → '.Money::format($result->centsAfter())
            .' (rozdiel '.($diff > 0 ? '+' : ($diff < 0 ? '−' : '')).Money::format(abs($diff)).').';
    }

    private static function modeHistory(MealGroup $group): ?string
    {
        $today = LocalTime::today()->toDateString();
        $planned = $group->modes->filter(fn (MealGroupMode $m) => $m->effective_from->toDateString() > $today);
        if ($planned->isEmpty()) {
            return null;
        }

        return 'Naplánované: '.$planned->map(fn ($m) => 'od '.$m->effective_from->format('j. n. Y').' '.mb_strtolower(strtok($m->mode->label(), ' ')))->join(', ');
    }

    private static function membersToday(MealGroup $group): int
    {
        $today = LocalTime::today()->toDateString();

        return EmployeeGroupMembership::query()
            ->from('employee_group_memberships as m')
            ->where('m.meal_group_id', $group->id)
            ->where('m.effective_from', '<=', $today)
            ->whereNotExists(fn ($q) => $q->from('employee_group_memberships as n')
                ->whereColumn('n.employee_id', 'm.employee_id')
                ->whereColumn('n.effective_from', '>', 'm.effective_from')
                ->where('n.effective_from', '<=', $today))
            ->count();
    }

    public static function getPages(): array
    {
        return ['index' => ManageMealGroups::route('/')];
    }
}
