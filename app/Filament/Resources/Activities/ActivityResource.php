<?php

namespace App\Filament\Resources\Activities;

use App\Enums\NavGroup;
use App\Enums\Role;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Models\Employee;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Support\LocalTime;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

/** Read-only audit log (spatie/laravel-activitylog). Admin only. */
class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Settings;

    protected static ?int $navigationSort = 90;

    protected static ?string $modelLabel = 'záznam';

    protected static ?string $pluralModelLabel = 'história zmien';

    protected static ?string $navigationLabel = 'História zmien';

    protected static ?string $slug = 'audit';

    public const LOGS = [
        'orders' => 'Objednávky',
        'locks' => 'Uzamknutia',
        'menus' => 'Jedálny lístok',
        'prices' => 'Ceny',
        'employees' => 'Zamestnanci',
        'users' => 'Používatelia',
        'settings' => 'Nastavenia',
    ];

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->hasRole(Role::Admin);
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function causerName(Activity $a): string
    {
        return match (true) {
            $a->causer instanceof User => $a->causer->name,
            $a->causer instanceof Employee => $a->causer->fullNameReversed().' (zamestnanec)',
            default => 'systém',
        };
    }

    public static function subjectName(Activity $a): string
    {
        $s = $a->subject;

        return match (true) {
            $s === null => class_basename((string) $a->subject_type).' #'.$a->subject_id,
            $s instanceof Order => 'Objednávka #'.$s->id.' – '.$s->employee?->fullNameReversed().', '.$s->date->format('j. n. Y'),
            $s instanceof Employee => $s->fullNameReversed().' ('.$s->osobne_cislo.')',
            $s instanceof User => $s->name,
            $s instanceof MenuItem => 'Menu '.$s->number.': '.$s->name,
            default => class_basename($s).' #'.$s->getKey(),
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['causer', 'subject']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Kedy')->dateTime('j. n. Y H:i:s', LocalTime::zone()),
                TextColumn::make('log_name')->label('Oblasť')->badge()->formatStateUsing(fn ($state) => self::LOGS[$state] ?? $state),
                TextColumn::make('description')->label('Čo')->wrap(),
                TextColumn::make('subject')->label('Čoho sa týka')->state(fn (Activity $record) => self::subjectName($record))->wrap(),
                TextColumn::make('causer')->label('Kto')->state(fn (Activity $record) => self::causerName($record)),
                TextColumn::make('properties.reason')->label('Dôvod')->placeholder('–')->wrap(),
            ])
            ->filters([
                SelectFilter::make('log_name')->label('Oblasť')->options(self::LOGS)->multiple(),
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label('Od')->native(false)->displayFormat('j. n. Y'),
                        DatePicker::make('until')->label('Do')->native(false)->displayFormat('j. n. Y'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', LocalTime::date($d)->utc()))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('created_at', '<', LocalTime::date($d)->addDay()->utc()))),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('created_at')->label('Kedy')->dateTime('j. n. Y H:i:s', LocalTime::zone()),
            TextEntry::make('causer')->label('Kto')->state(fn (Activity $record) => self::causerName($record)),
            TextEntry::make('description')->label('Čo'),
            TextEntry::make('subject')->label('Čoho sa týka')->state(fn (Activity $record) => self::subjectName($record)),
            KeyValueEntry::make('old')->label('Pred')->state(fn (Activity $record) => self::flat($record->properties['old'] ?? []))->columnSpanFull(),
            KeyValueEntry::make('new')->label('Po')->state(fn (Activity $record) => self::flat($record->properties['attributes'] ?? collect($record->properties)->except(['old', 'attributes'])->all()))->columnSpanFull(),
        ]);
    }

    /** @return array<string, string> */
    private static function flat(mixed $values): array
    {
        return collect($values)->map(fn ($v) => is_scalar($v) || $v === null ? (string) ($v === null ? '–' : (is_bool($v) ? ($v ? 'áno' : 'nie') : $v)) : json_encode($v, JSON_UNESCAPED_UNICODE))->all();
    }

    public static function getPages(): array
    {
        return ['index' => ListActivities::route('/')];
    }
}
