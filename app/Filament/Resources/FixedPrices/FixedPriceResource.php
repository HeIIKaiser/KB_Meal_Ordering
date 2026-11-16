<?php

namespace App\Filament\Resources\FixedPrices;

use App\Enums\NavGroup;
use App\Filament\Concerns\SentenceCaseLabels;
use App\Filament\Resources\FixedPrices\Pages\ManageFixedPrices;
use App\Models\Employee;
use App\Models\FixedPrice;
use App\Models\MealGroup;
use App\Models\MealSlot;
use App\Support\LocalTime;
use App\Support\Money;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Pevné ceny (pricing mode "fixed", e.g. self-employed): for a group or one person, all
 * meals or one meal, versioned by „Platí od“ (meal date). Orders keep the price they
 * were placed with.
 */
class FixedPriceResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = FixedPrice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Settings;

    protected static ?int $navigationSort = 12;

    protected static ?string $modelLabel = 'pevná cena';

    protected static ?string $pluralModelLabel = 'pevné ceny';

    protected static ?string $navigationLabel = 'Pevné ceny';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Radio::make('for')
                ->label('Pre')
                ->options(['group' => 'skupinu (predvolená cena)', 'employee' => 'jedného človeka (prednosť pred skupinou)'])
                ->default('group')
                ->inline()
                ->live()
                ->dehydrated(false)
                ->afterStateHydrated(fn (Radio $c, ?FixedPrice $record) => $c->state($record?->employee_id ? 'employee' : 'group'))
                ->disabledOn('edit'),
            Select::make('meal_group_id')
                ->label('Skupina')
                ->options(fn () => MealGroup::query()->orderBy('name')->pluck('name', 'id'))
                ->visible(fn (Get $get) => $get('for') !== 'employee')
                ->required(fn (Get $get) => $get('for') !== 'employee')
                ->disabledOn('edit'),
            Select::make('employee_id')
                ->label('Zamestnanec')
                ->searchable()
                ->getSearchResultsUsing(fn (string $search) => Employee::query()
                    ->where(fn ($q) => $q->where('last_name', 'like', "%$search%")->orWhere('first_name', 'like', "%$search%")->orWhere('osobne_cislo', 'like', "%$search%"))
                    ->alphabetical()->limit(30)->get()->mapWithKeys(fn (Employee $e) => [$e->id => $e->fullNameReversed().' ('.$e->osobne_cislo.')'])->all())
                ->getOptionLabelUsing(fn ($value) => ($e = Employee::find($value)) ? $e->fullNameReversed().' ('.$e->osobne_cislo.')' : null)
                ->visible(fn (Get $get) => $get('for') === 'employee')
                ->required(fn (Get $get) => $get('for') === 'employee')
                ->disabledOn('edit'),
            Select::make('meal_slot_id')
                ->label('Jedlo')
                ->options(fn () => MealSlot::query()->ordered()->pluck('name', 'id'))
                ->placeholder('všetky jedlá')
                ->helperText('Prázdne = rovnaká cena pre obed aj večeru. Cena pre konkrétne jedlo má prednosť.'),
            TextInput::make('amount_cents')
                ->label('Cena (€)')
                ->placeholder('5,00')
                ->required()
                ->formatStateUsing(fn ($state) => $state === null ? null : number_format($state / 100, 2, ',', ''))
                ->dehydrateStateUsing(fn ($state) => Money::parse((string) $state))
                ->rule(fn () => function (string $attribute, $value, Closure $fail) {
                    $cents = Money::parse((string) $value);
                    if ($cents === null || $cents < 0 || $cents > 100000) {
                        $fail('Zadajte cenu v eurách, napr. 5,00.');
                    }
                }),
            DatePicker::make('valid_from')
                ->label('Platí od (dátum jedla)')
                ->helperText('Platí pre jedlá od tohto dňa. Už existujúce objednávky si ponechajú cenu, za ktorú boli objednané.')
                ->required()
                ->native(false)
                ->displayFormat('j. n. Y')
                ->default(fn () => LocalTime::today()->toDateString()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['group', 'employee', 'mealSlot']))
            ->defaultSort('valid_from', 'desc')
            ->columns([
                TextColumn::make('for')
                    ->label('Pre')
                    ->state(fn (FixedPrice $r) => $r->employee ? $r->employee->fullNameReversed().' ('.$r->employee->osobne_cislo.')' : 'skupina '.$r->group?->name)
                    ->description(fn (FixedPrice $r) => $r->employee ? 'osobná cena' : null),
                TextColumn::make('mealSlot.name')->label('Jedlo')->placeholder('všetky'),
                TextColumn::make('amount_cents')->label('Cena')->formatStateUsing(fn (int $state) => Money::format($state)),
                TextColumn::make('valid_from')->label('Platí od')->date('j. n. Y', LocalTime::zone())->sortable(),
                TextColumn::make('status')
                    ->label('Stav')->badge()
                    ->state(fn (FixedPrice $r) => $r->valid_from->gt(LocalTime::today()) ? 'budúca' : 'platí / platila')
                    ->color(fn (string $state) => $state === 'budúca' ? 'warning' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('meal_group_id')->label('Skupina')->relationship('group', 'name'),
                SelectFilter::make('person')->label('Druh')->options(['group' => 'ceny skupín', 'employee' => 'osobné ceny'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'group' => $query->whereNull('employee_id'),
                        'employee' => $query->whereNotNull('employee_id'),
                        default => $query,
                    }),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageFixedPrices::route('/')];
    }
}
