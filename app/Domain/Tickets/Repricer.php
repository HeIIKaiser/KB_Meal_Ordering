<?php

namespace App\Domain\Tickets;

use App\Domain\Ordering\PriceResolver;
use App\Enums\OrderStatus;
use App\Enums\PricingMode;
use App\Models\Employee;
use App\Models\Order;
use App\Models\PriceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The repricing rule (ticket mode), for one employee and one month:
 *
 *   take all active orders whose meal is in ticket mode, sorted by meal date, slot
 *   order (Obed before Večera) and order id; the first N (N = allocation) are
 *   Zamestnanecká strava, the rest Dokupovaná strava, priced for each meal date.
 *
 * Orders whose meal date is in daily mode keep their snapshot — unless their mode
 * changed (group mode switch / group change); those slot-days are re-typed by the daily
 * rule (oldest order donated, the rest extra).
 *
 * Only orders whose mode, type or price actually changes are written, and each of
 * them gets one audit entry (event "repriced", with the cause). Callers run this
 * inside their transaction with the employee row locked.
 */
class Repricer
{
    public const CAUSES = [
        'place' => 'nová objednávka',
        'cancel' => 'zrušená objednávka',
        'allocation' => 'zmena počtu lístkov',
        'month_import' => 'konečné lístky (import)',
        'mode_switch' => 'zmena režimu skupiny',
        'group_change' => 'zmena skupiny zamestnanca',
    ];

    /** @var array<string, int> */
    private array $priceCache = [];

    public function __construct(
        private PriceResolver $prices,
        private TicketAllocation $allocation,
    ) {}

    public function reprice(Employee $employee, string $month, string $cause, ?Model $causer = null, ?EmployeeCalendar $calendar = null, ?string $reason = null): RepriceResult
    {
        $calendar ??= EmployeeCalendar::for($employee);
        $result = new RepriceResult;
        $this->priceCache = [];

        $orders = Order::query()
            ->where('employee_id', $employee->id)
            ->where('status', OrderStatus::Active->value)
            ->whereBetween('date', [Month::first($month)->toDateString(), Month::last($month)->toDateString()])
            ->with(['mealSlot', 'priceType'])
            ->get()
            ->sortBy(fn (Order $o) => [$o->date->toDateString(), $o->mealSlot->sort, $o->id])
            ->values();
        if ($orders->isEmpty()) {
            return $result;
        }

        $donated = PriceType::byCode(PriceType::DONATED);
        $extra = PriceType::byCode(PriceType::EXTRA);

        /** @var array<int, array{0: PricingMode, 1: PriceType}> $target order id → [mode, type] */
        $target = [];
        $modes = $orders->mapWithKeys(fn (Order $o) => [$o->id => $calendar->modeOn($o->date)]);

        // Ticket mode: the first N are donated.
        $ticketOrders = $orders->filter(fn (Order $o) => $modes[$o->id] === PricingMode::Tickets)->values();
        if ($ticketOrders->isNotEmpty()) {
            $n = $this->allocation->allocation($employee, $month, $calendar) ?? 0;
            foreach ($ticketOrders as $i => $o) {
                $target[$o->id] = [PricingMode::Tickets, $i < $n ? $donated : $extra];
            }
        }

        // Daily mode: re-type only slot-days that contain an order coming from ticket mode.
        $orders->filter(fn (Order $o) => $modes[$o->id] === PricingMode::Daily)
            ->groupBy(fn (Order $o) => $o->date->toDateString().'|'.$o->meal_slot_id)
            ->each(function (Collection $slotDay) use (&$target, $donated, $extra) {
                if ($slotDay->every(fn (Order $o) => $o->pricing_mode === PricingMode::Daily)) {
                    return; // snapshot stays
                }
                foreach ($slotDay->sortBy('id')->values() as $i => $o) {
                    $target[$o->id] = [PricingMode::Daily, $i === 0 ? $donated : $extra];
                }
            });

        // Write: demotions first, so the daily donated guard never sees two donated orders.
        $changed = $orders->filter(function (Order $o) use ($target) {
            if (! isset($target[$o->id])) {
                return false;
            }
            [$mode, $type] = $target[$o->id];

            return $o->pricing_mode !== $mode
                || $o->price_type_id !== $type->id
                || $o->unit_price_cents !== $this->price($type, $o->date->toDateString());
        })->sortBy(fn (Order $o) => $target[$o->id][1]->isDonated() ? 1 : 0);

        foreach ($changed as $o) {
            [$mode, $type] = $target[$o->id];
            $old = ['pricing_mode' => $o->pricing_mode->value, 'price_type' => $o->priceType->code, 'unit_price_cents' => $o->unit_price_cents];
            $oldType = $o->price_type_id;

            $o->update([
                'pricing_mode' => $mode,
                'price_type_id' => $type->id,
                'unit_price_cents' => $this->price($type, $o->date->toDateString()),
            ]);
            $o->setRelation('priceType', $type);

            activity('orders')
                ->performedOn($o)
                ->causedBy($causer)
                ->event('repriced')
                ->withProperties(array_filter([
                    'old' => $old,
                    'attributes' => ['pricing_mode' => $mode->value, 'price_type' => $type->code, 'unit_price_cents' => $o->unit_price_cents],
                    'cause' => $cause,
                    'reason' => $reason,
                    'date' => $o->date->toDateString(),
                    'meal_slot_id' => $o->meal_slot_id,
                    'employee_id' => $o->employee_id,
                ], fn ($v) => $v !== null))
                ->log('Cena prepočítaná ('.(self::CAUSES[$cause] ?? $cause).')');

            $result->add([
                'order_id' => $o->id, 'employee_id' => $o->employee_id, 'date' => $o->date->toDateString(), 'meal_slot_id' => $o->meal_slot_id,
                'old_type' => $oldType, 'new_type' => $type->id, 'old_cents' => $old['unit_price_cents'], 'new_cents' => $o->unit_price_cents,
            ]);
        }

        return $result;
    }

    private function price(PriceType $type, string $date): int
    {
        return $this->priceCache[$type->id.'|'.$date] ??= $this->prices->amountFor($type, $date);
    }
}
