<?php

namespace App\Filament\Resources\Employees;

use App\Domain\Employees\PinIssuer;
use App\Enums\NavGroup;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\Employee;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class EmployeeResource extends Resource
{
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
            TextInput::make('last_name')->label('Priezvisko')->required()->maxLength(100),
            TextInput::make('first_name')->label('Meno')->required()->maxLength(100),
            TextInput::make('department')
                ->label('Stredisko')
                ->maxLength(100)
                ->datalist(fn () => Employee::query()->whereNotNull('department')->distinct()->orderBy('department')->pluck('department')->all()),
            Toggle::make('is_active')
                ->label('Aktívny')
                ->helperText('Neaktívny zamestnanec sa nemôže prihlásiť. Jeho objednávky zostávajú v histórii.')
                ->default(true),
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
            ->columns([
                TextColumn::make('osobne_cislo')->label('Os. číslo')->searchable()->sortable(),
                TextColumn::make('last_name')
                    ->label('Priezvisko a meno')
                    ->formatStateUsing(fn (Employee $record) => $record->fullNameReversed())
                    ->searchable(['last_name', 'first_name'])
                    ->sortable(['last_name', 'first_name']),
                TextColumn::make('department')->label('Stredisko')->searchable()->sortable()->toggleable(),
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
                Filter::make('not_printed')
                    ->label('Lístok ešte nevytlačený')
                    ->query(fn ($query) => $query->whereNull('slip_printed_at')),
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
