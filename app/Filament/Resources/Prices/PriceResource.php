<?php

namespace App\Filament\Resources\Prices;

use App\Domain\Ordering\PriceResolver;
use App\Enums\NavGroup;
use App\Filament\Resources\Prices\Pages\ManagePrices;
use App\Models\Price;
use App\Models\PriceType;
use App\Support\LocalTime;
use App\Support\Money;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;
use UnitEnum;

/**
 * Versioned prices. A version applies to meals on/after valid_from; orders keep
 * the price they were placed with (snapshot), so a new version never changes
 * existing orders. Versions already in effect are read-only.
 */
class PriceResource extends Resource
{
    protected static ?string $model = Price::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyEuro;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Settings;

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'cena';

    protected static ?string $pluralModelLabel = 'ceny';

    protected static ?string $navigationLabel = 'Ceny';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('price_type_id')
                ->label('Typ')
                ->options(fn () => PriceType::query()->orderBy('sort')->pluck('name', 'id'))
                ->required()
                ->disabledOn('edit'),
            TextInput::make('amount_cents')
                ->label('Cena (€)')
                ->placeholder('1,50')
                ->required()
                ->formatStateUsing(fn ($state) => $state === null ? null : number_format($state / 100, 2, ',', ''))
                ->dehydrateStateUsing(fn ($state) => Money::parse((string) $state))
                ->rule(fn () => function (string $attribute, $value, Closure $fail) {
                    $cents = Money::parse((string) $value);
                    if ($cents === null || $cents < 0 || $cents > 100000) {
                        $fail('Zadajte cenu v eurách, napr. 1,50.');
                    }
                }),
            DatePicker::make('valid_from')
                ->label('Platí od (dátum jedla)')
                ->helperText('Nová cena platí pre jedlá od tohto dňa. Už existujúce objednávky si ponechajú pôvodnú cenu.')
                ->required()
                ->native(false)
                ->displayFormat('j. n. Y')
                ->minDate(fn () => LocalTime::today()->addDay())
                ->default(fn () => LocalTime::today()->addDay()->toDateString())
                ->rule(fn (Get $get, ?Price $record) => Rule::unique('prices', 'valid_from')
                    ->where('price_type_id', $get('price_type_id') ?? $record?->price_type_id)
                    ->ignore($record?->id))
                ->validationMessages(['unique' => 'Pre tento typ už existuje cena s rovnakým dátumom.']),
        ]);
    }

    public static function table(Table $table): Table
    {
        $current = fn (Price $p) => app(PriceResolver::class)->current($p->priceType, LocalTime::today())?->id === $p->id;

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['priceType']))
            ->defaultSort('valid_from', 'desc')
            ->description(fn () => 'Aktuálne: '.PriceType::query()->orderBy('sort')->get()
                ->map(fn (PriceType $t) => $t->name.' '.Money::format(app(PriceResolver::class)->current($t, LocalTime::today())?->amount_cents ?? 0))
                ->join(' · '))
            ->columns([
                TextColumn::make('priceType.name')->label('Typ'),
                TextColumn::make('amount_cents')->label('Cena')->formatStateUsing(fn (int $state) => Money::format($state)),
                TextColumn::make('valid_from')->label('Platí od')->date('j. n. Y')->sortable(),
                TextColumn::make('status')
                    ->label('Stav')
                    ->badge()
                    ->state(fn (Price $record) => match (true) {
                        $current($record) => 'aktuálna',
                        $record->valid_from->gt(LocalTime::today()) => 'budúca',
                        default => 'staršia',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'aktuálna' => 'success',
                        'budúca' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')->label('Zadané')->dateTime('j. n. Y H:i', LocalTime::zone())->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('price_type_id')->label('Typ')->relationship('priceType', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePrices::route('/')];
    }
}
