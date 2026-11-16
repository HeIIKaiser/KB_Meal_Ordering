<?php

namespace App\Filament\Resources\Employees;

use App\Domain\Employees\PinIssuer;
use App\Domain\Ordering\FixedPriceResolver;
use App\Domain\Ordering\OrderingException;
use App\Domain\Tickets\EmployeeCalendar;
use App\Domain\Tickets\MealGroupService;
use App\Enums\NavGroup;
use App\Enums\PricingMode;
use App\Filament\Concerns\SentenceCaseLabels;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\FixedPrices\FixedPriceResource;
use App\Filament\Resources\MealGroups\MealGroupResource;
use App\Models\Employee;
use App\Models\MealGroup;
use App\Models\MealSlot;
use App\Models\User;
use App\Support\LocalTime;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use UnitEnum;

class EmployeeResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::People;

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'zamestnanec';

    protected static ?string $pluralModelLabel = 'zamestnanci';

    protected static ?string $navigationLabel = 'Zamestnanci';

    protected static ?string $recordTitleAttribute = 'last_name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('osobne_cislo')
                ->label('Osobné číslo')
                ->required()
                ->maxLength(32)
                ->unique(ignoreRecord: true),
            TextInput::make('vema_cislo')
                ->label('Vema číslo')
                ->helperText('Osobné číslo v mzdovom systéme Vema (len číslice; v exporte bez núl na začiatku).')
                ->regex('/^\d{1,16}$/')
                ->validationMessages(['regex' => 'Vema číslo môže obsahovať len číslice.'])
                ->unique(ignoreRecord: true)
                ->maxLength(16),
            TextInput::make('last_name')->label('Priezvisko')->required()->maxLength(100),
            TextInput::make('first_name')->label('Meno')->required()->maxLength(100),
            TextInput::make('department')
                ->label('Stredisko')
                ->maxLength(100)
                ->datalist(fn () => Employee::query()->whereNotNull('department')->distinct()->orderBy('department')->pluck('department')->all()),
            Select::make('max_meals_per_day')
                ->label('Max. jedál za deň (dochádzka)')
                ->options([1 => '1 – len obedy účtovať', 2 => '2'])
                ->placeholder('bez obmedzenia')
                ->helperText('Platí pre lístky a povinnú stravu podľa dochádzky (odhad aj konečný výpočet).'),
            Toggle::make('is_active')
                ->label('Aktívny')
                ->helperText('Neaktívny zamestnanec sa nemôže prihlásiť. Jeho objednávky zostávajú v histórii.')
                ->default(true),
            Select::make('meal_group_id')
                ->label('Skupina stravníkov')
                ->options(fn (?Employee $record) => static::groupOptions($record))
                ->placeholder('bez skupiny (denný režim)')
                ->dehydrated(false)
                ->live()
                ->visible(fn () => MealGroup::query()->exists())
                ->helperText(fn (?Employee $record) => $record ? static::groupHistory($record) : null),
            DatePicker::make('group_from')
                ->label('Skupina platí od')
                ->native(false)->displayFormat('j. n. Y')
                ->dehydrated(false)
                ->required(fn () => MealGroup::query()->exists())
                ->live()
                ->visible(fn () => MealGroup::query()->exists())
                ->helperText(fn (Get $get, ?Employee $record) => static::groupChangeHint($record, $get('meal_group_id'), $get('group_from'))),
            Placeholder::make('fixed_price')
                ->label('Pevná cena')
                ->visible(fn (?Employee $record) => $record && EmployeeCalendar::for($record)->modeOn(LocalTime::today()) === PricingMode::Fixed)
                ->content(fn (?Employee $record) => static::fixedPriceText($record)),
            Section::make('Fakturačné údaje')
                ->description('Pre skupiny s vysporiadaním faktúrou (SZČO). Vypisujú sa do exportu Fakturácia.')
                ->visible(fn (Get $get, ?Employee $record) => ($get('meal_group_id') && MealGroup::query()->find($get('meal_group_id'))?->isInvoiced())
                    || ($record && filled($record->billing_name.$record->ico.$record->dic.$record->billing_address.$record->billing_email)))
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('billing_name')->label('Fakturačný názov')->maxLength(150),
                    TextInput::make('billing_email')->label('E-mail pre faktúry')->email()->maxLength(150),
                    TextInput::make('ico')->label('IČO')->regex('/^\d{6,8}$/')->validationMessages(['regex' => 'IČO má 6 až 8 číslic.'])->maxLength(8),
                    TextInput::make('dic')->label('DIČ')->regex('/^\d{10}$/')->validationMessages(['regex' => 'DIČ má 10 číslic.'])->maxLength(10),
                    TextInput::make('billing_address')->label('Adresa')->maxLength(255)->columnSpanFull(),
                    TextInput::make('billing_note')->label('Poznámka k fakturácii')->maxLength(255)->columnSpanFull(),
                ]),
            Select::make('user_id')
                ->label('Prepojený účet správcu')
                ->helperText('Len ak má zamestnanec aj prístup do správy (kuchyňa, mzdy, admin).')
                ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->nullable()
                ->unique(ignoreRecord: true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_name')
            ->modifyQueryUsing(fn ($query) => $query->with('groupMemberships.group.modes'))
            ->columns([
                TextColumn::make('osobne_cislo')->label('Os. číslo')->searchable()->sortable(),
                TextColumn::make('last_name')
                    ->label('Priezvisko a meno')
                    ->formatStateUsing(fn (Employee $record) => $record->fullNameReversed())
                    ->searchable(['last_name', 'first_name'])
                    ->sortable(['last_name', 'first_name']),
                TextColumn::make('department')->label('Stredisko')->searchable()->sortable()->toggleable(),
                TextColumn::make('vema_cislo')->label('Vema č.')->searchable()->placeholder('–')->toggleable(),
                TextColumn::make('meal_group')
                    ->label('Skupina')
                    ->state(fn (Employee $record) => EmployeeCalendar::fromMemberships($record, $record->groupMemberships)->today()?->name)
                    ->placeholder('–')
                    ->description(fn (Employee $record) => static::plannedGroup($record))
                    ->visible(fn () => MealGroup::query()->exists())
                    ->toggleable(),
                IconColumn::make('is_active')->label('Aktívny')->boolean(),
                TextColumn::make('pin_status')
                    ->label('PIN')
                    ->state(fn (Employee $record) => $record->pinStatus())
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'na lístku' => 'success',
                        'zmenil zamestnanec' => 'gray',
                        default => 'danger',
                    })
                    ->tooltip(fn (Employee $record) => $record->pinStatus() === 'na lístku' ? 'PIN sa dá vytlačiť na lístok' : 'PIN sa nedá vytlačiť – pri potrebe použite Reset PIN'),
                TextColumn::make('slip_printed_at')->label('Lístok vytlačený')->since()->placeholder('ešte nie')->sortable()->toggleable(),
                TextColumn::make('pin_changed_at')->label('PIN zmenený')->since()->placeholder('–')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_login_at')->label('Posledné prihlásenie')->since()->placeholder('nikdy')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Aktívny')->default(true),
                Filter::make('no_vema')
                    ->label('Bez Vema čísla')
                    ->query(fn ($query) => $query->whereNull('vema_cislo')),
                Filter::make('not_printed')
                    ->label('Lístok ešte nevytlačený')
                    ->query(fn ($query) => $query->whereNull('slip_printed_at')),
                SelectFilter::make('meal_group')
                    ->label('Skupina stravníkov')
                    ->options(fn () => ['none' => 'bez skupiny'] + MealGroup::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->visible(fn () => MealGroup::query()->exists())
                    ->query(fn ($query, array $data) => static::filterByGroupToday($query, $data['value'] ?? null)),
                SelectFilter::make('department')
                    ->label('Stredisko')
                    ->options(fn () => Employee::query()->whereNotNull('department')->distinct()->orderBy('department')->pluck('department', 'department')->all()),
            ])
            ->recordActions([
                EditAction::make(),
                static::printSlipAction(),
                static::resetPinAction(),
            ])
            ->toolbarActions([
                BulkAction::make('printSlips')
                    ->label('Tlačiť prístupové lístky')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->authorize(fn () => auth()->user()?->isAdmin())
                    ->action(fn (Collection $records) => redirect(static::slipsUrl($records->modelKeys())))
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('slips')
                    ->label('Nové PINy + prístupové lístky')
                    ->icon(Heroicon::OutlinedKey)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Vygenerovať nové PINy a vytlačiť lístky?')
                    ->modalDescription('Vybraným zamestnancom sa vygeneruje nový PIN (starý prestane platiť a budú odhlásení na všetkých zariadeniach). Ak chcete len znova vytlačiť súčasné PINy, použite „Tlačiť prístupové lístky“.')
                    ->modalSubmitActionLabel('Vygenerovať a tlačiť')
                    ->authorize(fn () => auth()->user()?->isAdmin())
                    ->action(fn (Collection $records) => static::issueAndPrint($records))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    /** "Obed 5,00 € · Večera 5,00 € (osobná cena)" + link to Pevné ceny. */
    public static function fixedPriceText(?Employee $record): HtmlString
    {
        $resolver = app(FixedPriceResolver::class);
        $parts = MealSlot::query()->active()->ordered()->get()->map(function (MealSlot $slot) use ($record, $resolver) {
            $p = $resolver->find($record, LocalTime::today(), $slot);

            return $slot->name.' '.($p ? Money::format($p->amount_cents).($p->employee_id ? ' (osobná)' : '') : '– nie je nastavená');
        })->join(' · ');

        return new HtmlString(e($parts).' · <a class="font-semibold text-primary-600 underline" href="'.e(FixedPriceResource::getUrl()).'">Pevné ceny</a>');
    }

    /** @return array<int, string> active groups, plus the employee's current one even if inactive */
    public static function groupOptions(?Employee $record): array
    {
        $current = $record ? EmployeeCalendar::for($record)->memberships()->pluck('meal_group_id')->filter()->all() : [];

        return MealGroup::query()->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $current))
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    /** "Teraz: 8 h · od 1. 12. 2026: 12 h" */
    public static function groupHistory(Employee $record): ?string
    {
        $calendar = EmployeeCalendar::for($record);
        if ($calendar->memberships()->isEmpty()) {
            return 'Zamestnanec bez skupiny je v dennom režime.';
        }

        return 'Teraz: '.($calendar->today()?->name ?? 'bez skupiny').(static::plannedGroup($record) ? ' · '.static::plannedGroup($record) : '');
    }

    public static function plannedGroup(Employee $record): ?string
    {
        $today = LocalTime::today()->toDateString();
        $memberships = $record->relationLoaded('groupMemberships') ? $record->groupMemberships : $record->groupMemberships()->with('group')->get();

        return $memberships->filter(fn ($m) => $m->effective_from->toDateString() > $today)
            ->map(fn ($m) => 'od '.$m->effective_from->format('j. n. Y').': '.($m->group?->name ?? 'bez skupiny'))
            ->join(', ') ?: null;
    }

    /** Warns before a group change that would reprice already ordered meals. */
    public static function groupChangeHint(?Employee $record, mixed $groupId, ?string $from): ?string
    {
        if (! $from) {
            return null;
        }
        if (! $record) {
            return 'Pre nového zamestnanca zvyčajne 1. deň tohto mesiaca.';
        }
        try {
            $preview = app(MealGroupService::class)->previewAssign($record, $groupId ? (int) $groupId : null, $from, auth()->user());
        } catch (OrderingException $e) {
            return $e->getMessage();
        }

        return $preview->isEmpty()
            ? 'Zmena skupiny platí pre jedlá od tohto dňa. Už objednané ceny sa nezmenia.'
            : 'Pozor: '.MealGroupResource::describe($preview);
    }

    /** Employees whose group TODAY is $value ("none" = without a group). */
    public static function filterByGroupToday($query, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $today = LocalTime::today()->toDateString();
        $current = fn ($q) => $q->from('employee_group_memberships as m')
            ->whereColumn('m.employee_id', 'employees.id')
            ->where('m.effective_from', '<=', $today)
            ->whereNotExists(fn ($n) => $n->from('employee_group_memberships as n')
                ->whereColumn('n.employee_id', 'm.employee_id')
                ->whereColumn('n.effective_from', '>', 'm.effective_from')
                ->where('n.effective_from', '<=', $today));

        if ($value === 'none') {
            $query->whereNotExists(fn ($q) => $current($q)->whereNotNull('m.meal_group_id'));
        } else {
            $query->whereExists(fn ($q) => $current($q)->where('m.meal_group_id', (int) $value));
        }
    }

    public static function resetPinAction(): Action
    {
        return Action::make('resetPin')
            ->label('Reset PIN')
            ->icon(Heroicon::OutlinedKey)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(fn (Employee $record) => 'Nový PIN pre '.$record->fullName().'?')
            ->modalDescription('Starý PIN prestane platiť a zamestnanec bude odhlásený na všetkých zariadeniach. Potom sa otvorí lístok s novým PINom.')
            ->modalSubmitActionLabel('Vygenerovať a tlačiť lístok')
            ->authorize(fn () => auth()->user()?->isAdmin())
            ->action(fn (Employee $record) => static::issueAndPrint(collect([$record])));
    }

    /** Print the access slip with the CURRENT PIN (nothing changes). */
    public static function printSlipAction(): Action
    {
        return Action::make('printSlip')
            ->label('Lístok')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->authorize(fn () => auth()->user()?->isAdmin())
            ->url(fn (Employee $record) => static::slipsUrl([$record->id]), shouldOpenInNewTab: true);
    }

    /** Generates NEW PINs and redirects to the printable slips. */
    public static function issueAndPrint(iterable $employees)
    {
        return redirect(static::slipsUrl(app(PinIssuer::class)->issue($employees, auth()->user())));
    }

    /** @param  array<int>  $ids */
    public static function slipsUrl(array $ids): string
    {
        return route('admin.slips', ['zamestnanci' => implode(',', $ids)]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployees::route('/'),
            'create' => CreateEmployee::route('/create'),
            'edit' => EditEmployee::route('/{record}/edit'),
        ];
    }
}
