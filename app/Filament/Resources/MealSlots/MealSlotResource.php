<?php

namespace App\Filament\Resources\MealSlots;

use App\Enums\NavGroup;
use App\Filament\Resources\MealSlots\Pages\ManageMealSlots;
use App\Models\MealSlot;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Meal slots (Obed, Večera, …) with their automatic ordering cutoff. */
class MealSlotResource extends Resource
{
    protected static ?string $model = MealSlot::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Settings;

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'jedlo dňa';

    protected static ?string $pluralModelLabel = 'jedlá dňa a uzávierky';

    protected static ?string $navigationLabel = 'Jedlá a uzávierky';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Názov')->required()->maxLength(50)->placeholder('Obed'),
            TextInput::make('name_accusative')
                ->label('Tvar vo vete')
                ->helperText('„Objednávky na … sú už uzavreté.“ – napr. obed, večeru')
                ->maxLength(50),
            TextInput::make('code')
                ->label('Kód (pre import)')
                ->helperText('Malé písmená bez diakritiky, napr. obed, vecera. Neskôr sa nedá zmeniť.')
                ->required()
                ->alphaDash()
                ->dehydrateStateUsing(fn ($state) => mb_strtolower(trim((string) $state)))
                ->maxLength(32)
                ->unique(ignoreRecord: true)
                ->disabledOn('edit'),
            Select::make('cutoff_days_before')
                ->label('Uzávierka objednávok')
                ->options([0 => 'v ten istý deň', 1 => 'deň vopred', 2 => '2 dni vopred'])
                ->required()
                ->default(0),
            TimePicker::make('cutoff_time')->label('o (čas)')->seconds(false)->required()->default('09:00'),
            TextInput::make('sort')->label('Poradie')->numeric()->integer()->minValue(0)->default(10)->required(),
            Toggle::make('has_soup')->label('Má polievku dňa')->helperText('Polievka sa zobrazí pri menu; neobjednáva sa ani neplatí zvlášť.')->default(false),
            Toggle::make('is_active')->label('Aktívne')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('Názov'),
                TextColumn::make('code')->label('Kód'),
                TextColumn::make('cutoff')
                    ->label('Uzávierka')
                    ->state(fn (MealSlot $r) => [0 => 'v ten istý deň', 1 => 'deň vopred', 2 => '2 dni vopred'][$r->cutoff_days_before].' o '.substr($r->cutoff_time, 0, 5)),
                IconColumn::make('has_soup')->label('Polievka')->boolean(),
                IconColumn::make('is_active')->label('Aktívne')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMealSlots::route('/')];
    }
}
