<?php

namespace App\Filament\Resources\ClosedDays;

use App\Enums\NavGroup;
use App\Filament\Resources\ClosedDays\Pages\ManageClosedDays;
use App\Models\ClosedDay;
use App\Models\MealSlot;
use App\Support\LocalTime;
use App\Support\SlovakDate;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use UnitEnum;

/** Days (or day + slot) without meals — holidays etc. */
class ClosedDayResource extends Resource
{
    protected static ?string $model = ClosedDay::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Menus;

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'zatvorený deň';

    protected static ?string $pluralModelLabel = 'zatvorené dni';

    protected static ?string $navigationLabel = 'Zatvorené dni';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('date')->label('Dátum')->required()->native(false)->displayFormat('j. n. Y'),
            Select::make('meal_slot_id')
                ->label('Jedlo')
                ->options(fn () => MealSlot::query()->ordered()->pluck('name', 'id'))
                ->placeholder('celý deň')
                ->helperText('Prázdne = zatvorené celý deň.'),
            TextInput::make('note')->label('Poznámka pre zamestnancov')->placeholder('Sviatok')->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('mealSlot'))
            ->defaultSort('date')
            ->columns([
                TextColumn::make('date')->label('Dátum')->formatStateUsing(fn ($state) => SlovakDate::dayShort($state).' '.$state->format('j. n. Y'))->sortable(),
                TextColumn::make('mealSlot.name')->label('Jedlo')->placeholder('celý deň'),
                TextColumn::make('note')->label('Poznámka'),
            ])
            ->filters([
                Filter::make('upcoming')
                    ->label('Len dnešné a budúce')
                    ->default()
                    ->query(fn ($query) => $query->where('date', '>=', LocalTime::today()->toDateString())),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageClosedDays::route('/')];
    }
}
