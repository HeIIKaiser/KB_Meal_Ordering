<?php

namespace App\Livewire;

use App\Domain\Ordering\Actor;
use App\Domain\Ordering\AvailabilityService;
use App\Domain\Ordering\ExtraConfirmationRequired;
use App\Domain\Ordering\OrderingException;
use App\Domain\Ordering\OrderService;
use App\Domain\Ordering\SlotAvailability;
use App\Domain\Ordering\SlotState;
use App\Models\Employee;
use App\Models\MealSlot;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\PriceType;
use App\Support\LocalTime;
use App\Support\Money;
use App\Support\SlovakDate;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The employee's one-screen ordering UI: week strip + a card per slot with
 * menu options as large tap targets. Tap = order, tap again = cancel.
 *
 * All rules are enforced by OrderService; this component only routes taps
 * into the right dialog and shows Slovak feedback.
 */
#[Layout('layouts.employee')]
#[Title('Objednávky')]
class OrderScreen extends Component
{
    /** Monday of the displayed week (Y-m-d). */
    #[Url(as: 'tyzden', history: true)]
    public string $week = '';

    /** Day selected on mobile (Y-m-d); on desktop the whole week is visible. */
    public string $selected = '';

    /**
     * Open confirmation dialog, or null. Locked: the client can't tamper with it
     * (and OrderService re-checks everything anyway).
     *
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $dialog = null;

    /** @var array{type: string, text: string}|null */
    public ?array $flash = null;

    public function mount(): void
    {
        $today = LocalTime::today();
        $monday = $this->parseMonday($this->week) ?? $today->startOfWeek();
        $this->week = $monday->toDateString();
        $this->selected = $today->between($monday, $monday->addDays(6)) ? $today->toDateString() : $this->week;
    }

    public function previousWeek(): void
    {
        $this->goToWeek(CarbonImmutable::parse($this->week)->subWeek(), selectLast: true);
    }

    public function nextWeek(): void
    {
        $this->goToWeek(CarbonImmutable::parse($this->week)->addWeek());
    }

    public function thisWeek(): void
    {
        $this->goToWeek(LocalTime::today()->startOfWeek());
        $this->selected = LocalTime::today()->toDateString();
    }

    /** Tap on a menu option. */
    public function choose(int $itemId): void
    {
        $this->flash = null;
        $this->dialog = null;

        $item = MenuItem::query()->with('menu.mealSlot')->find($itemId);
        if (! $item) {
            $this->error('Toto jedlo už nie je v ponuke.');

            return;
        }

        $slot = $item->menu->mealSlot;
        $date = $item->menu->date;
        $availability = app(AvailabilityService::class)->for($date, $slot);
        $mine = $this->myOrdersIn($date, $slot);

        if (! $availability->isOrderable()) {
            $this->error($availability->refusalMessage($slot));

            return;
        }

        // Tapping an option I already have → confirm cancellation (the extra one first).
        $onItem = $mine->where('menu_item_id', $item->id)->sortByDesc(fn (Order $o) => $o->priceType->sort)->first();
        if ($onItem) {
            $this->dialog = [
                'type' => 'cancel',
                'order' => $onItem->id,
                'title' => 'Zrušiť objednávku?',
                'text' => $this->describe($item, $date, $slot).($onItem->priceType->isDonated() ? '' : ' (Extra, '.Money::format($onItem->unit_price_cents).')'),
            ];

            return;
        }

        // Another option already ordered in this slot → switch, or add an extra.
        if ($mine->isNotEmpty()) {
            $switchable = $mine->sortBy(fn (Order $o) => $o->priceType->sort)->first();
            $extraPrice = null;
            try {
                $quote = app(OrderService::class)->quote($this->employee(), $date, $slot);
                $extraPrice = $quote->isExtra() ? $quote->unitPriceCents : null;
            } catch (OrderingException) {
                // limit reached → only switching is possible
            }

            $this->dialog = [
                'type' => 'change',
                'order' => $switchable->id,
                'item' => $item->id,
                'title' => 'Zmeniť objednávku?',
                'text' => 'Máte objednané '.$switchable->menuItem->label().': '.$switchable->menuItem->name.'.',
                'switch_label' => 'Zmeniť na '.$item->label().': '.$item->name,
                'extra_label' => $extraPrice !== null ? 'Pridať extra za '.Money::format($extraPrice) : null,
            ];

            return;
        }

        $this->place($item, acceptExtra: false);
    }

    public function confirmCancel(): void
    {
        if (($this->dialog['type'] ?? null) !== 'cancel') {
            return;
        }

        $order = $this->employee()->orders()->with('menuItem')->find($this->dialog['order']);
        $this->dialog = null;

        $this->attempt(function () use ($order) {
            app(OrderService::class)->cancel($order, Actor::employee($this->employee()));
            $this->success('Zrušené: '.$order->menuItem->label().' – '.$order->menuItem->name);
        });
    }

    public function confirmSwitch(): void
    {
        if (($this->dialog['type'] ?? null) !== 'change') {
            return;
        }

        $order = $this->employee()->orders()->find($this->dialog['order']);
        $item = MenuItem::query()->find($this->dialog['item']);
        $this->dialog = null;

        $this->attempt(function () use ($order, $item) {
            app(OrderService::class)->switchItem($order, $item, Actor::employee($this->employee()));
            $this->success('Zmenené na '.$item->label().': '.$item->name);
        });
    }

    /** "Pridať extra" in the change dialog, or confirmation of the extra-price dialog. */
    public function confirmExtra(): void
    {
        if (! in_array($this->dialog['type'] ?? null, ['change', 'extra'], true)) {
            return;
        }

        $item = MenuItem::query()->find($this->dialog['item']);
        $this->dialog = null;

        if ($item) {
            $this->place($item, acceptExtra: true);
        }
    }

    public function closeDialog(): void
    {
        $this->dialog = null;
    }

    public function render()
    {
        $monday = CarbonImmutable::parse($this->week, LocalTime::zone());
        $sunday = $monday->addDays(6);
        $days = collect(range(0, 6))->map(fn ($i) => $monday->addDays($i));

        $slots = MealSlot::query()->active()->ordered()->get();
        $availability = app(AvailabilityService::class)->forPeriod($monday, $sunday, $slots);

        $menus = Menu::query()
            ->with('items')
            ->whereBetween('date', [$monday->toDateString(), $sunday->toDateString()])
            ->whereIn('meal_slot_id', $slots->pluck('id'))
            ->get()
            ->keyBy(fn (Menu $m) => $m->date->toDateString().'|'.$m->meal_slot_id);

        $orders = $this->employee()->orders()
            ->active()
            ->with('priceType')
            ->whereBetween('date', [$monday->toDateString(), $sunday->toDateString()])
            ->get();

        return view('livewire.order-screen', [
            'days' => $days,
            'today' => LocalTime::today()->toDateString(),
            'mealSlots' => $slots,
            'availability' => $availability,
            'menus' => $menus,
            'ordersByItem' => $orders->groupBy('menu_item_id'),
            'orderDays' => $orders->pluck('date')->map->toDateString()->unique()->flip(),
        ]);
    }

    // ------------------------------------------------------------------------

    private function place(MenuItem $item, bool $acceptExtra): void
    {
        $item->loadMissing('menu.mealSlot');

        try {
            $order = app(OrderService::class)->place($this->employee(), $item, Actor::employee($this->employee()), $acceptExtra);
            $this->success(
                'Objednané: '.$item->label().' – '.$item->name.' ('.Money::format($order->unit_price_cents).($order->price_type_id === PriceType::byCode(PriceType::DONATED)->id ? '' : ', extra').')'
            );
        } catch (ExtraConfirmationRequired $e) {
            $this->dialog = [
                'type' => 'extra',
                'item' => $item->id,
                'title' => 'Objednať extra jedlo?',
                'text' => $e->getMessage().' '.$this->describe($item, $item->menu->date, $item->menu->mealSlot),
                'extra_label' => 'Áno, objednať za '.Money::format($e->quote->unitPriceCents),
            ];
        } catch (OrderingException $e) {
            $this->error($e->getMessage());
        } catch (AuthorizationException) {
            $this->error('Na túto akciu nemáte oprávnenie.');
        }
    }

    private function attempt(callable $action): void
    {
        try {
            $action();
        } catch (OrderingException $e) {
            $this->error($e->getMessage());
        } catch (AuthorizationException|\TypeError) {
            $this->error('Objednávku sa nepodarilo nájsť. Obnovte stránku.');
        }
    }

    /** @return Collection<int, Order> */
    private function myOrdersIn(CarbonImmutable $date, MealSlot $slot): Collection
    {
        return $this->employee()->orders()
            ->active()
            ->with(['priceType', 'menuItem'])
            ->where('date', $date->toDateString())
            ->where('meal_slot_id', $slot->id)
            ->get();
    }

    private function describe(MenuItem $item, CarbonImmutable $date, MealSlot $slot): string
    {
        return $slot->name.' '.SlovakDate::dayShort($date).' '.SlovakDate::dm($date).' – '.$item->label().': '.$item->name;
    }

    private function goToWeek(CarbonImmutable $monday, bool $selectLast = false): void
    {
        $this->week = $monday->toDateString();
        $this->selected = $selectLast ? $monday->addDays(6)->toDateString() : $this->week;
        $this->dialog = null;
        $this->flash = null;
    }

    private function parseMonday(string $value): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return LocalTime::date($value)->startOfWeek();
        } catch (\Throwable) {
            return null;
        }
    }

    private function employee(): Employee
    {
        $employee = Auth::guard('employee')->user();
        abort_unless($employee instanceof Employee, 401);

        return $employee;
    }

    private function success(string $text): void
    {
        $this->flash = ['type' => 'ok', 'text' => $text];
    }

    private function error(string $text): void
    {
        $this->flash = ['type' => 'error', 'text' => $text];
    }

    /** Status line under the slot name, e.g. "otvorené do 09:00". */
    public static function statusLabel(SlotAvailability $a, CarbonImmutable $day): string
    {
        return match ($a->state) {
            SlotState::Open => 'otvorené do '.($a->cutoffAt->isSameDay($day)
                ? $a->cutoffAt->format('H:i')
                : SlovakDate::dayShort($a->cutoffAt).' '.SlovakDate::dm($a->cutoffAt).' '.$a->cutoffAt->format('H:i')),
            SlotState::Closed => trim('uzavreté '.($a->closedDay?->note ? '– '.$a->closedDay->note : '')),
            SlotState::NoMenu => 'menu zatiaľ nie je',
            default => $a->state->label(),
        };
    }
}
