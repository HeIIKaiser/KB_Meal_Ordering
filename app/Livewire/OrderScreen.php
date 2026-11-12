<?php

namespace App\Livewire;

use App\Domain\Ordering\Actor;
use App\Domain\Ordering\AvailabilityService;
use App\Domain\Ordering\ExtraConfirmationRequired;
use App\Domain\Ordering\OrderingException;
use App\Domain\Ordering\OrderService;
use App\Domain\Ordering\PriceResolver;
use App\Domain\Ordering\SlotAvailability;
use App\Domain\Ordering\SlotState;
use App\Domain\Tickets\EmployeeCalendar;
use App\Domain\Tickets\Month;
use App\Domain\Tickets\TicketAllocation;
use App\Enums\PricingMode;
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
use Illuminate\Support\Str;
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

        // Tapping an option I already have → cancel it (the extra one first), or buy one more of the same.
        $onItem = $mine->where('menu_item_id', $item->id)->sortByDesc(fn (Order $o) => $o->priceType->sort)->first();
        if ($onItem) {
            $more = $this->morePrice($date, $slot);
            $count = $mine->where('menu_item_id', $item->id)->count();

            $this->dialog = [
                'type' => 'cancel',
                'order' => $onItem->id,
                'item' => $item->id,
                'title' => $more !== null ? 'Zrušiť alebo dokúpiť?' : 'Zrušiť objednávku?',
                'text' => ($count > 1 ? 'Máte objednané '.$count.'×: ' : 'Máte objednané: ').$this->describe($item, $date, $slot).'.',
                'cancel_label' => match (true) {
                    $count > 1 && ! $onItem->priceType->isDonated() => 'Zrušiť jedno dokupované ('.Money::format($onItem->unit_price_cents).')',
                    $count > 1 => 'Zrušiť jedno ('.Money::format($onItem->unit_price_cents).')',
                    ! $onItem->priceType->isDonated() => 'Zrušiť objednávku ('.Money::format($onItem->unit_price_cents).')',
                    default => 'Zrušiť objednávku',
                },
                'extra_label' => $more !== null ? 'Dokúpiť ďalšie rovnaké jedlo za '.$more : null,
            ];

            return;
        }

        // Another option already ordered in this slot → switch, or add an extra.
        if ($mine->isNotEmpty()) {
            $switchable = $mine->sortBy(fn (Order $o) => $o->priceType->sort)->first();
            $more = $this->morePrice($date, $slot);

            $this->dialog = [
                'type' => 'change',
                'order' => $switchable->id,
                'item' => $item->id,
                'title' => 'Zmeniť objednávku?',
                'text' => 'Máte objednané '.$switchable->menuItem->label().': '.$switchable->menuItem->name.'.',
                'switch_label' => 'Zmeniť na '.$item->label().': '.$item->name,
                'extra_label' => $more !== null ? 'Dokúpiť ďalšie jedlo za '.$more : null,
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
            $cancelled = app(OrderService::class)->cancel($order, Actor::employee($this->employee()));
            $promoted = $cancelled->getRelation('promoted')?->loadMissing(['menuItem', 'mealSlot']);
            $sameSlotDay = $promoted && $promoted->date->equalTo($order->date) && $promoted->meal_slot_id === $order->meal_slot_id;
            $this->success('Zrušené: '.$order->menuItem->label().' – '.$order->menuItem->name
                .($promoted ? '. '.($sameSlotDay ? '' : $this->when($promoted).' ').$promoted->menuItem->label().' je teraz '.Str::lower($promoted->priceType->name).' za '.Money::format($promoted->unit_price_cents).'.' : ''));
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

    /** "Dokúpiť" in the cancel/change dialog, or confirmation of the extra-price dialog. */
    public function confirmExtra(): void
    {
        if (! in_array($this->dialog['type'] ?? null, ['cancel', 'change', 'extra'], true) || empty($this->dialog['item'])) {
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

        // Desktop: every day column shares the same row tracks (CSS subgrid), so Obed,
        // soup, Menu 1, Menu 2 and Večera line up across the week. Rows per slot =
        // header + soup (if any day has one) + the most options on any day.
        $ordered = $orders->pluck('menu_item_id')->flip();
        $slotRows = $slots->mapWithKeys(function (MealSlot $slot) use ($menus, $days, $ordered) {
            $dayMenus = $days->map(fn ($d) => $menus[$d->toDateString().'|'.$slot->id] ?? null)->filter();
            $visible = $dayMenus->map(fn (Menu $m) => $m->items->filter(fn ($i) => $i->is_active || isset($ordered[$i->id]))->count());
            $soup = $dayMenus->contains(fn (Menu $m) => filled($m->soup_name));

            return [$slot->id => ['soup' => $soup, 'rows' => 1 + ($soup ? 1 : 0) + max(1, (int) $visible->max())]];
        });

        return view('livewire.order-screen', [
            'slotRows' => $slotRows,
            'weekRows' => 1 + $slotRows->sum('rows'),
            'days' => $days,
            'today' => LocalTime::today()->toDateString(),
            'mealSlots' => $slots,
            'availability' => $availability,
            'menus' => $menus,
            'ordersByItem' => $orders->groupBy('menu_item_id'),
            'orderDays' => $orders->pluck('date')->map->toDateString()->unique()->flip(),
            'tickets' => $this->ticketChips($days),
        ]);
    }

    /**
     * Ticket mode: "Zostáva 7 z 20 lístkov – november" for the month of each viewed day.
     *
     * @return array{days: array<string, string>, months: array<string, array{text: string, empty: bool}>}
     */
    private function ticketChips(Collection $days): array
    {
        $calendar = EmployeeCalendar::for($this->employee());
        $dayMonth = [];
        foreach ($days as $day) {
            if ($calendar->modeOn($day) === PricingMode::Tickets) {
                $dayMonth[$day->toDateString()] = Month::of($day);
            }
        }

        $months = [];
        foreach (array_unique($dayMonth) as $month) {
            $s = app(TicketAllocation::class)->summary($this->employee(), $month, $calendar);
            if ($s === null) {
                continue;
            }
            if ($s['left'] > 0) {
                $text = 'Zostáva '.$s['left'].' z '.$s['allocated'].' '.($s['allocated'] === 1 ? 'lístka' : 'lístkov').' – '.Month::name($month);
            } else {
                try {
                    $price = Money::format(app(PriceResolver::class)->amountFor(PriceType::byCode(PriceType::EXTRA), Month::first($month)));
                } catch (OrderingException) {
                    $price = 'plnej cene';
                }
                $text = 'Lístky minuté – ďalšie jedlá po '.$price.' ('.Month::name($month).')';
            }
            $months[$month] = ['text' => $text, 'empty' => $s['left'] === 0];
        }

        return ['days' => $dayMonth, 'months' => $months];
    }

    // ------------------------------------------------------------------------

    private function place(MenuItem $item, bool $acceptExtra): void
    {
        $item->loadMissing('menu.mealSlot');

        try {
            $order = app(OrderService::class)->place($this->employee(), $item, Actor::employee($this->employee()), $acceptExtra);
            // Ticket mode: an earlier meal can take the ticket of a later one, which then costs 6 €.
            $moved = $order->relationLoaded('repriced') ? $order->getRelation('repriced') : collect();
            $this->success(
                'Objednané: '.$item->label().' – '.$item->name.' ('.Money::format($order->unit_price_cents).($order->price_type_id === PriceType::byCode(PriceType::DONATED)->id ? '' : ', dokupovaná strava').')'
                .$moved->map(fn (Order $o) => '. '.$this->when($o).' '.$o->menuItem->label().' je teraz za '.Money::format($o->unit_price_cents))->join('').($moved->isNotEmpty() ? '.' : '')
            );
        } catch (ExtraConfirmationRequired $e) {
            $this->dialog = [
                'type' => 'extra',
                'item' => $item->id,
                'title' => 'Dokúpiť ďalšie jedlo?',
                'text' => $e->getMessage().' '.$this->describe($item, $item->menu->date, $item->menu->mealSlot),
                'extra_label' => 'Áno, objednať za '.Money::format($e->quote->unitPriceCents),
            ];
        } catch (OrderingException $e) {
            $this->error($e->getMessage());
        } catch (AuthorizationException) {
            $this->error('Na túto akciu nemáte oprávnenie.');
        }
    }

    /**
     * Price label of one more meal in this slot ("6,00 €", or "1,50 € (lístok)" while
     * tickets last), or null when nothing more can be ordered (daily extra cap reached).
     */
    private function morePrice(CarbonImmutable $date, MealSlot $slot): ?string
    {
        try {
            $quote = app(OrderService::class)->quote($this->employee(), $date, $slot);
        } catch (OrderingException) {
            return null;
        }

        return match (true) {
            $quote->isExtra() => Money::format($quote->unitPriceCents),
            $quote->isTicketMode() => Money::format($quote->unitPriceCents).' (lístok)',
            default => null,
        };
    }

    /** "Obed Št 19. 11." */
    private function when(Order $order): string
    {
        $order->loadMissing('mealSlot');

        return $order->mealSlot->name.' '.SlovakDate::dayShort($order->date).' '.SlovakDate::dm($order->date);
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
