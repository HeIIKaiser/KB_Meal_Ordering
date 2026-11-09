<?php

namespace App\Filament\Resources\Orders;

use App\Domain\Ordering\Actor;
use App\Domain\Ordering\OrderingException;
use App\Domain\Ordering\OrderService;
use App\Enums\NavGroup;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Employee;
use App\Models\MealSlot;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\PriceType;
use App\Support\LocalTime;
use App\Support\Money;
use App\Support\SlovakDate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Orders overview for all staff; admin may order on behalf of an employee and
 * correct orders (also after lock, with a mandatory reason). Every write goes
 * through OrderService.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Orders;

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'objednávka';

    protected static ?string $pluralModelLabel = 'objednávky';

    protected static ?string $navigationLabel = 'Všetky objednávky';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee', 'mealSlot', 'menuItem', 'priceType', 'createdBy', 'cancelledBy']))
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('date')
                    ->label('Dátum')
                    ->formatStateUsing(fn ($state) => SlovakDate::dayShort($state).' '.$state->format('j. n. Y'))
                    ->sortable(),
                TextColumn::make('mealSlot.name')->label('Jedlo'),
                TextColumn::make('employee.last_name')
                    ->label('Zamestnanec')
                    ->formatStateUsing(fn (Order $record) => $record->employee->fullNameReversed())
                    ->description(fn (Order $record) => $record->employee->osobne_cislo)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('employee', fn ($q) => $q
                        ->where('last_name', 'like', "%$search%")
                        ->orWhere('first_name', 'like', "%$search%")
                        ->orWhere('osobne_cislo', $search))),
                TextColumn::make('menuItem.name')
                    ->label('Menu')
                    ->formatStateUsing(fn (Order $record) => $record->menuItem->label().': '.$record->menuItem->name)
                    ->wrap(),
                TextColumn::make('priceType.name')
                    ->label('Typ')
                    ->badge()
                    ->color(fn (Order $record) => $record->priceType->isDonated() ? 'success' : 'warning'),
                TextColumn::make('unit_price_cents')->label('Cena')->formatStateUsing(fn (int $state) => Money::format($state))->alignEnd(),
                TextColumn::make('status')
                    ->label('Stav')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                    ->color(fn (OrderStatus $state) => $state === OrderStatus::Active ? 'success' : 'danger'),
                TextColumn::make('created_at')
                    ->label('Vytvorené')
                    ->dateTime('j. n. H:i', LocalTime::zone())
                    ->description(fn (Order $record) => $record->createdBy ? 'za: '.$record->createdBy->name : 'sám')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('cancelled_at')
                    ->label('Zrušené')
                    ->dateTime('j. n. H:i', LocalTime::zone())
                    ->description(fn (Order $record) => $record->cancelledBy?->name)
                    ->placeholder('–')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('note')->label('Poznámka')->placeholder('–')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label('Od')->native(false)->displayFormat('j. n. Y')->default(fn () => LocalTime::today()->startOfWeek()->toDateString()),
                        DatePicker::make('until')->label('Do')->native(false)->displayFormat('j. n. Y')->default(fn () => LocalTime::today()->startOfWeek()->addDays(13)->toDateString()),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('date', '>=', LocalTime::date($d)->toDateString()))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('date', '<=', LocalTime::date($d)->toDateString())))
                    ->indicateUsing(fn (array $data) => array_filter([
                        ($data['from'] ?? null) ? 'Od '.LocalTime::date($data['from'])->format('j. n. Y') : null,
                        ($data['until'] ?? null) ? 'Do '.LocalTime::date($data['until'])->format('j. n. Y') : null,
                    ])),
                SelectFilter::make('meal_slot_id')->label('Jedlo')->relationship('mealSlot', 'name'),
                SelectFilter::make('price_type_id')->label('Typ')->relationship('priceType', 'name'),
                SelectFilter::make('status')
                    ->label('Stav')
                    ->options(collect(OrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                    ->default(OrderStatus::Active->value),
                SelectFilter::make('employee_id')
                    ->label('Zamestnanec')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => static::employeeOptions($search))
                    ->getOptionLabelUsing(fn ($value) => Employee::find($value)?->fullNameReversed()),
            ])
            ->filtersFormColumns(3)
            ->recordActions([
                ActionGroup::make([
                    static::switchAction(),
                    static::changeTypeAction(),
                    static::cancelAction(),
                ])->label('Opraviť')->button()->color('gray')->size('sm')
                    ->visible(fn (Order $record) => $record->isActive() && auth()->user()?->can('update', $record)),
            ])
            ->headerActions([static::createOnBehalfAction()]);
    }

    public static function createOnBehalfAction(): Action
    {
        return Action::make('createOnBehalf')
            ->label('Objednať za zamestnanca')
            ->icon(Heroicon::OutlinedPlus)
            ->authorize(fn () => auth()->user()?->can('create', Order::class))
            ->modalHeading('Objednať za zamestnanca')
            ->modalDescription('Pre ľudí bez telefónu. Typ ceny sa určí automaticky (prvý obed a prvá večera dňa = zamestnanecká strava, ďalšie = dokupovaná).')
            ->schema([
                Select::make('employee_id')
                    ->label('Zamestnanec')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => static::employeeOptions($search, onlyActive: true))
                    ->getOptionLabelUsing(fn ($value) => Employee::find($value)?->fullNameReversed())
                    ->required(),
                DatePicker::make('date')->label('Dátum')->native(false)->displayFormat('j. n. Y')->required()->live()
                    ->default(fn () => LocalTime::today()->toDateString()),
                Select::make('meal_slot_id')->label('Jedlo')->options(fn () => MealSlot::query()->active()->ordered()->pluck('name', 'id'))->required()->live(),
                Select::make('menu_item_id')
                    ->label('Menu')
                    ->options(fn (Get $get) => static::itemOptions($get('date'), $get('meal_slot_id')))
                    ->required()
                    ->live()
                    ->helperText(fn (Get $get) => ($get('date') && $get('meal_slot_id') && static::itemOptions($get('date'), $get('meal_slot_id')) === []) ? 'Na tento deň nie je menu.' : null),
                Placeholder::make('quote')
                    ->label('Cena')
                    ->content(function (Get $get) {
                        $employee = $get('employee_id') ? Employee::find($get('employee_id')) : null;
                        $slot = $get('meal_slot_id') ? MealSlot::find($get('meal_slot_id')) : null;
                        if (! $employee || ! $slot || ! $get('date')) {
                            return '–';
                        }
                        try {
                            $q = app(OrderService::class)->quote($employee, $get('date'), $slot);

                            return $q->priceType->name.' – '.Money::format($q->unitPriceCents);
                        } catch (OrderingException $e) {
                            return $e->getMessage();
                        }
                    }),
                TextInput::make('reason')->label('Dôvod')->helperText('Povinný, ak je už po uzávierke alebo je jedlo uzamknuté.')->maxLength(255),
                TextInput::make('note')->label('Poznámka k objednávke')->maxLength(255),
            ])
            ->action(function (array $data, Action $action) {
                $item = MenuItem::query()->findOrFail($data['menu_item_id']);
                static::attempt($action, function () use ($data, $item) {
                    $order = app(OrderService::class)->place(
                        Employee::query()->findOrFail($data['employee_id']),
                        $item,
                        Actor::staff(auth()->user(), $data['reason'] ?? null),
                        acceptExtra: true,
                        note: $data['note'] ?? null,
                    );
                    Notification::make()->title('Objednané')->body($order->priceType->name.', '.Money::format($order->unit_price_cents))->success()->send();
                });
            });
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancelOrder')
            ->label('Zrušiť')
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->schema([TextInput::make('reason')->label('Dôvod')->helperText('Povinný po uzávierke.')->maxLength(255)])
            ->modalHeading('Zrušiť objednávku')
            ->action(fn (Order $record, array $data, Action $action) => static::attempt($action, function () use ($record, $data) {
                app(OrderService::class)->cancel($record, Actor::staff(auth()->user(), $data['reason'] ?? null));
                Notification::make()->title('Objednávka zrušená')->success()->send();
            }));
    }

    public static function switchAction(): Action
    {
        return Action::make('switchItem')
            ->label('Zmeniť jedlo')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->schema(fn (Order $record) => [
                Select::make('menu_item_id')
                    ->label('Nové menu')
                    ->options(static::itemOptions($record->date->toDateString(), $record->meal_slot_id, except: $record->menu_item_id))
                    ->required(),
                TextInput::make('reason')->label('Dôvod')->helperText('Povinný po uzávierke.')->maxLength(255),
            ])
            ->action(fn (Order $record, array $data, Action $action) => static::attempt($action, function () use ($record, $data) {
                app(OrderService::class)->switchItem($record, MenuItem::query()->findOrFail($data['menu_item_id']), Actor::staff(auth()->user(), $data['reason'] ?? null));
                Notification::make()->title('Jedlo zmenené')->success()->send();
            }));
    }

    public static function changeTypeAction(): Action
    {
        return Action::make('changeType')
            ->label('Zmeniť typ ceny')
            ->icon(Heroicon::OutlinedCurrencyEuro)
            ->schema(fn (Order $record) => [
                Select::make('price_type_id')
                    ->label('Typ')
                    ->options(PriceType::query()->orderBy('sort')->whereKeyNot($record->price_type_id)->pluck('name', 'id'))
                    ->required(),
                TextInput::make('reason')->label('Dôvod')->required()->maxLength(255),
            ])
            ->modalDescription('Cena sa nastaví podľa platného cenníka pre deň jedla.')
            ->action(fn (Order $record, array $data, Action $action) => static::attempt($action, function () use ($record, $data) {
                $order = app(OrderService::class)->changePriceType($record, PriceType::query()->findOrFail($data['price_type_id']), Actor::staff(auth()->user(), $data['reason']));
                Notification::make()->title('Typ ceny zmenený')->body($order->priceType->name.', '.Money::format($order->unit_price_cents))->success()->send();
            }));
    }

    /** Runs an OrderService call and turns refusals into a red notification. */
    private static function attempt(Action $action, callable $callback): void
    {
        try {
            $callback();
        } catch (OrderingException $e) {
            Notification::make()->title('Nedá sa')->body($e->getMessage())->danger()->send();
            $action->halt();
        } catch (AuthorizationException) {
            Notification::make()->title('Na túto akciu nemáte oprávnenie.')->danger()->send();
            $action->halt();
        }
    }

    /** @return array<int, string> */
    public static function itemOptions(?string $date, mixed $slotId, ?int $except = null): array
    {
        if (! $date || ! $slotId) {
            return [];
        }

        $menu = Menu::query()->where('date', LocalTime::date($date)->toDateString())->where('meal_slot_id', $slotId)->first();

        return $menu
            ? $menu->items()->where('is_active', true)->when($except, fn ($q) => $q->whereKeyNot($except))->get()
                ->mapWithKeys(fn (MenuItem $i) => [$i->id => $i->label().': '.$i->name])->all()
            : [];
    }

    /** @return array<int, string> */
    public static function employeeOptions(string $search, bool $onlyActive = false): array
    {
        return Employee::query()
            ->when($onlyActive, fn ($q) => $q->where('is_active', true))
            ->where(fn ($q) => $q->where('last_name', 'like', "%$search%")->orWhere('first_name', 'like', "%$search%")->orWhere('osobne_cislo', 'like', "$search%"))
            ->alphabetical()
            ->limit(30)
            ->get()
            ->mapWithKeys(fn (Employee $e) => [$e->id => $e->fullNameReversed().' ('.$e->osobne_cislo.')'])
            ->all();
    }

    public static function getPages(): array
    {
        return ['index' => ListOrders::route('/')];
    }
}
