<?php

namespace App\Domain\Ordering;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Employee;
use App\Models\MealSlot;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\PriceType;
use App\Support\LocalTime;
use App\Support\Settings;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only place where orders are created or changed.
 *
 * Rules (brief §3):
 *  - Price type is assigned automatically: the first type (by sort) whose per-slot
 *    daily limit is not yet used up. With the default setup: no active donated
 *    order in the slot/day → Darované, otherwise Extra (up to max_extra_per_slot).
 *  - Price is resolved for the meal date and snapshotted on the order.
 *  - Cancelling never re-types other orders.
 *  - Employees act only while the slot is orderable; staff (admin) may act any
 *    time, but outside the window a reason is mandatory. Everything is audited.
 *  - Concurrency: every write runs in a transaction that first locks the
 *    employee row (SELECT … FOR UPDATE), so two quick taps are serialised. A
 *    unique index on orders(employee, date, slot, donated_guard) is the last
 *    line of defence against two active donated orders.
 */
class OrderService
{
    public function __construct(
        private AvailabilityService $availability,
        private PriceResolver $prices,
        private Settings $settings,
    ) {}

    /** What the employee's next order in this slot/day would be (type + price). */
    public function quote(Employee $employee, DateTimeInterface|string $date, MealSlot $slot): PriceQuote
    {
        return $this->nextQuote($this->activeCounts($employee, $date, $slot), $date, $slot);
    }

    /**
     * Order a menu item. Throws ExtraConfirmationRequired if the order would be an
     * Extra one and $acceptExtra is false (the UI must show the price first).
     */
    public function place(Employee $employee, MenuItem $item, Actor $actor, bool $acceptExtra = false, ?string $note = null): Order
    {
        $this->assertMayActFor($actor, $employee);
        $item->loadMissing('menu.mealSlot');
        $menu = $item->menu;
        $slot = $menu->mealSlot;

        if (! $employee->is_active) {
            throw new OrderingException('Váš účet nie je aktívny. Kontaktujte administrátora.', 'employee_inactive');
        }
        if (! $item->is_active) {
            throw new OrderingException('Toto jedlo už nie je v ponuke.', 'item_inactive');
        }

        return $this->transaction(function () use ($employee, $item, $menu, $slot, $actor, $acceptExtra, $note) {
            $this->lockEmployee($employee);
            $this->assertWindow($menu->date, $slot, $actor);

            $quote = $this->nextQuote($this->activeCounts($employee, $menu->date, $slot), $menu->date, $slot);

            if ($quote->isExtra() && ! $acceptExtra) {
                throw new ExtraConfirmationRequired($quote);
            }

            $order = Order::create([
                'employee_id' => $employee->id,
                'date' => $menu->date,
                'meal_slot_id' => $slot->id,
                'menu_item_id' => $item->id,
                'price_type_id' => $quote->priceType->id,
                'unit_price_cents' => $quote->unitPriceCents,
                'status' => OrderStatus::Active,
                'created_by_user_id' => $actor->user?->id,
                'note' => $note,
            ]);

            $this->audit($order, 'created', $actor, 'Objednávka vytvorená', [
                'menu_item' => $item->label().': '.$item->name,
                'price_type' => $quote->priceType->code,
                'unit_price_cents' => $quote->unitPriceCents,
            ]);

            return $order;
        });
    }

    /** Switch an active order to another option of the same menu ("Zmeniť objednávku"). Keeps type and price. */
    public function switchItem(Order $order, MenuItem $newItem, Actor $actor): Order
    {
        $this->assertMayActFor($actor, $order->employee);
        $newItem->loadMissing('menu');

        if (! $newItem->menu->date->equalTo($order->date) || $newItem->menu->meal_slot_id !== $order->meal_slot_id) {
            throw new OrderingException('Jedlo nepatrí do rovnakého menu.', 'item_mismatch');
        }
        if (! $newItem->is_active) {
            throw new OrderingException('Toto jedlo už nie je v ponuke.', 'item_inactive');
        }

        return $this->transaction(function () use ($order, $newItem, $actor) {
            $this->lockEmployee($order->employee);
            $order = $this->freshActive($order);
            $this->assertWindow($order->date, $order->mealSlot, $actor);

            $old = $order->menuItem;
            if ($old->id === $newItem->id) {
                return $order;
            }

            $order->update(['menu_item_id' => $newItem->id]);
            $order->setRelation('menuItem', $newItem);

            $this->audit($order, 'switched', $actor, 'Objednávka zmenená', [
                'old' => ['menu_item' => $old->label().': '.$old->name],
                'attributes' => ['menu_item' => $newItem->label().': '.$newItem->name],
            ]);

            return $order;
        });
    }

    public function cancel(Order $order, Actor $actor): Order
    {
        $this->assertMayActFor($actor, $order->employee);

        return $this->transaction(function () use ($order, $actor) {
            $this->lockEmployee($order->employee);
            $order = $this->freshActive($order);
            $this->assertWindow($order->date, $order->mealSlot, $actor);

            $order->update([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $actor->user?->id,
            ]);

            $this->audit($order, 'cancelled', $actor, 'Objednávka zrušená');

            return $order;
        });
    }

    /**
     * Admin correction: change the price type of an order (always audited with a
     * reason). The unit price is re-resolved for the new type unless given.
     */
    public function changePriceType(Order $order, PriceType $type, Actor $actor, ?int $unitPriceCents = null): Order
    {
        if (! $actor->isStaff()) {
            throw new AuthorizationException;
        }
        $this->assertAdmin($actor);
        if ($actor->reason === null) {
            throw new OrderingException('Pri oprave je povinný dôvod.', 'reason_required');
        }

        return $this->transaction(function () use ($order, $type, $actor, $unitPriceCents) {
            $this->lockEmployee($order->employee);
            $order = $this->freshActive($order);

            if ($type->isDonated() && $order->price_type_id !== $type->id
                && $this->activeCounts($order->employee, $order->date, $order->mealSlot)->get($type->id, 0) >= $this->limitFor($type)) {
                throw new OrderingException('Zamestnanec už má na tento deň darované jedlo ('.$order->mealSlot->accusative().').', 'limit_reached');
            }

            $old = ['price_type' => $order->priceType->code, 'unit_price_cents' => $order->unit_price_cents];
            $order->update([
                'price_type_id' => $type->id,
                'unit_price_cents' => $unitPriceCents ?? $this->prices->amountFor($type, $order->date),
            ]);
            $order->setRelation('priceType', $type);

            $this->audit($order, 'price_type_changed', $actor, 'Zmenený typ ceny', [
                'old' => $old,
                'attributes' => ['price_type' => $type->code, 'unit_price_cents' => $order->unit_price_cents],
            ]);

            return $order;
        });
    }

    // ------------------------------------------------------------------------

    /** @return Collection<int, int> active order count keyed by price_type_id */
    private function activeCounts(Employee $employee, DateTimeInterface|string $date, MealSlot $slot): Collection
    {
        return Order::query()
            ->where('employee_id', $employee->id)
            ->where('date', LocalTime::date($date)->toDateString())
            ->where('meal_slot_id', $slot->id)
            ->active()
            ->selectRaw('price_type_id, COUNT(*) as aggregate')
            ->groupBy('price_type_id')
            ->pluck('aggregate', 'price_type_id')
            ->map(fn ($n) => (int) $n);
    }

    /** @param Collection<int, int> $counts */
    private function nextQuote(Collection $counts, DateTimeInterface|string $date, MealSlot $slot): PriceQuote
    {
        $types = PriceType::query()->orderBy('sort')->orderBy('id')->get();

        foreach ($types as $type) {
            $limit = $this->limitFor($type);
            if ($limit === null || $counts->get($type->id, 0) < $limit) {
                return new PriceQuote($type, $this->prices->amountFor($type, $date));
            }
        }

        throw new OrderingException('Na '.$slot->accusative().' v tento deň už máte objednaný maximálny počet jedál.', 'limit_reached');
    }

    /** Max active orders of this type per employee, slot and day (null = unlimited). */
    private function limitFor(PriceType $type): ?int
    {
        return match (true) {
            $type->isDonated() => $type->per_slot_daily_limit ?? 1,
            $type->isExtra() => $type->per_slot_daily_limit ?? $this->settings->maxExtraPerSlot(),
            default => $type->per_slot_daily_limit,
        };
    }

    private function assertWindow(DateTimeInterface $date, MealSlot $slot, Actor $actor): void
    {
        $availability = $this->availability->for($date, $slot);

        if ($availability->isOrderable()) {
            return;
        }

        if (! $actor->isStaff()) {
            throw OrderingException::notOrderable($availability, $slot);
        }

        $this->assertAdmin($actor);

        if ($actor->reason === null) {
            throw new OrderingException('Objednávky sú uzavreté ('.$availability->state->label().'). Pri úprave po uzávierke je povinný dôvod.', 'reason_required');
        }
    }

    private function assertMayActFor(Actor $actor, Employee $employee): void
    {
        if ($actor->isStaff()) {
            $this->assertAdmin($actor);

            return;
        }

        if ($actor->employee?->id !== $employee->id) {
            throw new AuthorizationException;
        }
    }

    private function assertAdmin(Actor $actor): void
    {
        if (! $actor->user->is_active || ! $actor->user->hasRole(Role::Admin)) {
            throw new AuthorizationException;
        }
    }

    private function freshActive(Order $order): Order
    {
        $fresh = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

        if (! $fresh->isActive()) {
            throw new OrderingException('Táto objednávka už bola zrušená.', 'already_cancelled');
        }

        return $fresh;
    }

    private function lockEmployee(Employee $employee): void
    {
        Employee::query()->whereKey($employee->id)->lockForUpdate()->value('id');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        try {
            return DB::transaction($callback, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw new OrderingException('Objednávku sa nepodarilo uložiť, skúste to prosím znova.', 'conflict');
        }
    }

    private function audit(Order $order, string $event, Actor $actor, string $description, array $properties = []): void
    {
        activity('orders')
            ->performedOn($order)
            ->causedBy($actor->causer())
            ->event($event)
            ->withProperties(array_filter([
                ...$properties,
                'date' => $order->date->toDateString(),
                'meal_slot_id' => $order->meal_slot_id,
                'employee_id' => $order->employee_id,
                'reason' => $actor->reason,
            ], fn ($v) => $v !== null))
            ->log($description);
    }
}
