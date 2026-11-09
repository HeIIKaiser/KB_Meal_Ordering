<?php

namespace App\Filament\Resources\Users;

use App\Enums\NavGroup;
use App\Enums\Role;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use UnitEnum;

/** Staff accounts for /admin (kitchen, payroll, admin). */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::People;

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'správca';

    protected static ?string $pluralModelLabel = 'správcovia';

    protected static ?string $navigationLabel = 'Správcovia';

    public static function form(Schema $schema): Schema
    {
        $self = fn (?User $record) => $record?->is(auth()->user());

        return $schema->components([
            TextInput::make('name')->label('Meno')->required()->maxLength(255),
            TextInput::make('email')->label('E-mail')->email()->required()->maxLength(255)->unique(ignoreRecord: true)
                ->dehydrateStateUsing(fn ($state) => mb_strtolower(trim($state))),
            CheckboxList::make('roles')
                ->label('Role')
                ->options(collect(Role::cases())->mapWithKeys(fn (Role $r) => [$r->value => $r->label()]))
                ->descriptions([
                    Role::Kitchen->value => 'prehľady, tlač, uzamknutie, jedálny lístok',
                    Role::Payroll->value => 'iba čítanie objednávok a exporty',
                    Role::Admin->value => 'všetko vrátane zamestnancov, cien a opráv',
                ])
                ->required()
                ->rule(fn (?User $record) => function (string $attribute, $value, Closure $fail) use ($record) {
                    if ($record?->is(auth()->user()) && ! in_array(Role::Admin->value, (array) $value, true)) {
                        $fail('Sami sebe nemôžete odobrať rolu administrátora.');
                    }
                }),
            Toggle::make('is_active')->label('Aktívny')->default(true)->disabled($self),
            TextInput::make('password')
                ->label(fn (string $operation) => $operation === 'create' ? 'Heslo' : 'Nové heslo (nechajte prázdne, ak sa nemení)')
                ->password()
                ->revealable()
                ->minLength(12)
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn ($state) => filled($state))
                ->dehydrateStateUsing(fn ($state) => Hash::make($state)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Meno')->searchable()->sortable(),
                TextColumn::make('email')->label('E-mail')->searchable(),
                TextColumn::make('roles')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Role::tryFrom($state)?->label() ?? $state),
                IconColumn::make('two_factor')
                    ->label('2FA')
                    ->state(fn (User $r) => filled($r->app_authentication_secret))
                    ->boolean(),
                IconColumn::make('is_active')->label('Aktívny')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('disable2fa')
                    ->label('Vypnúť 2FA')
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->color('warning')
                    ->visible(fn (User $r) => filled($r->app_authentication_secret))
                    ->requiresConfirmation()
                    ->modalDescription('Použite, ak správca stratil telefón s overovacou aplikáciou. Po prihlásení si 2FA môže znova zapnúť v profile.')
                    ->authorize(fn () => auth()->user()?->isAdmin())
                    ->action(function (User $record) {
                        $record->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();
                        activity('users')->performedOn($record)->causedBy(auth()->user())->event('2fa_disabled')->log('2FA vypnuté administrátorom');
                        Notification::make()->title('2FA vypnuté')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsers::route('/')];
    }
}
