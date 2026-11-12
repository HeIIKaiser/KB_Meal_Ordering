<?php

namespace App\Domain\Exports;

use App\Domain\Tickets\ClosedMonths;
use App\Domain\Tickets\EmployeeCalendar;
use App\Domain\Tickets\Month;
use App\Enums\OrderStatus;
use App\Enums\PricingMode;
use App\Models\Employee;
use App\Models\EmployeeGroupMembership;
use App\Models\MealSlot;
use App\Models\Order;
use App\Models\PriceType;
use App\Models\TicketMonthCount;
use App\Models\TicketOverride;
use App\Support\LocalTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Per-employee monthly numbers for payroll / attendance matching: counts per
 * day × price type (× slot) and € sums from each order's snapshot price.
 * Active orders only. One grouped query for the whole month.
 *
 * Layout-independent — the XLSX layout lives in MonthlyMatrixXlsx.
 */
final class MonthlyData
{
    /**
     * @param  Collection<int, array{employee: Employee, counts: array<string, array<int, int>>, cents: array<string, int>, group: ?string, mode: string, tickets: array{allocated: int, used: int, expired: int}|null}>  $employees
     *                                                                                                                                                                                                                                counts keyed "typeId|slotId" → [day => n]; cents keyed "typeId|slotId"; mode denny|listky|zmiesany
     */
    private function __construct(
        public readonly CarbonImmutable $month,
        public readonly Collection $priceTypes,
        public readonly Collection $slots,
        public readonly Collection $employees,
        public readonly bool $closed = false,
    ) {}

    /** Someone was in ticket mode this month (adds the ticket note to the export). */
    public function hasTickets(): bool
    {
        return $this->employees->contains(fn ($e) => $e['tickets'] !== null);
    }

    /** Text instead of / next to the estimate disclaimer. */
    public function note(): string
    {
        if ($this->closed) {
            return Money::CLOSED_NOTE;
        }

        return Money::ESTIMATE_DISCLAIMER.($this->hasTickets() ? ' '.Money::TICKETS_NOTE : '');
    }

    public static function for(string $yearMonth, bool $includeWithoutOrders = false): self
    {
        $month = LocalTime::date($yearMonth.'-01');
        $from = $month->toDateString();
        $to = $month->endOfMonth()->toDateString();

        $rows = Order::query()
            ->where('status', OrderStatus::Active->value)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('employee_id, date, meal_slot_id, price_type_id, COUNT(*) as n, SUM(unit_price_cents) as cents')
            ->groupBy('employee_id', 'date', 'meal_slot_id', 'price_type_id')
            ->toBase()
            ->get();

        $employees = Employee::query()
            ->where(fn ($q) => $q->whereIn('id', $rows->pluck('employee_id')->unique())
                ->when($includeWithoutOrders, fn ($q) => $q->orWhere('is_active', true)))
            ->alphabetical()
            ->get();

        $byEmployee = $rows->groupBy('employee_id');
        $tickets = self::tickets($employees, $month->format('Y-m'));

        $data = $employees->map(function (Employee $e) use ($byEmployee, $tickets) {
            $counts = [];
            $cents = [];
            foreach ($byEmployee[$e->id] ?? [] as $r) {
                $key = $r->price_type_id.'|'.$r->meal_slot_id;
                $day = (int) LocalTime::date($r->date)->format('j');
                $counts[$key][$day] = ($counts[$key][$day] ?? 0) + (int) $r->n;
                $cents[$key] = ($cents[$key] ?? 0) + (int) $r->cents;
            }

            return ['employee' => $e, 'counts' => $counts, 'cents' => $cents, ...$tickets[$e->id]];
        })->values();

        return new self(
            $month,
            PriceType::query()->orderBy('sort')->orderBy('id')->get(),
            MealSlot::query()->ordered()->get(),
            $data,
            app(ClosedMonths::class)->isClosed($month->format('Y-m')),
        );
    }

    /**
     * Group, mode (denny | listky | zmiesany) and tickets (only for the ticket-mode part
     * of the month) per employee, batch-loaded.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, array{group: ?string, mode: string, tickets: array{allocated: int, used: int, expired: int}|null}>
     */
    private static function tickets(Collection $employees, string $month): array
    {
        $memberships = EmployeeGroupMembership::query()->whereIn('employee_id', $employees->pluck('id'))->with('group.modes')->get()->groupBy('employee_id');
        $overrides = TicketOverride::query()->where('month', $month)->pluck('tickets', 'employee_id');
        $groupCounts = TicketMonthCount::query()->where('month', $month)->pluck('tickets', 'meal_group_id');
        $used = Order::query()
            ->where('status', OrderStatus::Active->value)
            ->where('pricing_mode', PricingMode::Tickets->value)
            ->whereBetween('date', [Month::first($month)->toDateString(), Month::last($month)->toDateString()])
            ->selectRaw('employee_id, COUNT(*) as n')->groupBy('employee_id')->pluck('n', 'employee_id');

        $out = [];
        foreach ($employees as $e) {
            $calendar = EmployeeCalendar::fromMemberships($e, $memberships[$e->id] ?? collect());
            $modes = $calendar->memberships()->isEmpty() ? [PricingMode::Daily] : $calendar->modesIn($month);
            $ticketGroup = $calendar->ticketGroupFor($month);
            $tickets = null;
            if ($ticketGroup) {
                $allocated = (int) ($overrides[$e->id] ?? $groupCounts[$ticketGroup->id] ?? $ticketGroup->default_tickets);
                $n = (int) ($used[$e->id] ?? 0);
                $tickets = ['allocated' => $allocated, 'used' => min($n, $allocated), 'expired' => max(0, $allocated - $n)];
            }
            $out[$e->id] = [
                'group' => ($ticketGroup ?? $calendar->groupOn(Month::last($month)))?->name,
                'mode' => count($modes) > 1 ? 'zmiesany' : $modes[0]->exportCode(),
                'tickets' => $tickets,
            ];
        }

        return $out;
    }

    public function daysInMonth(): int
    {
        return $this->month->daysInMonth;
    }

    /** Count for one employee entry, optional type/slot/day filters (null = all). */
    public static function count(array $entry, ?int $typeId = null, ?int $slotId = null, ?int $day = null): int
    {
        $sum = 0;
        foreach ($entry['counts'] as $key => $days) {
            [$t, $s] = array_map('intval', explode('|', $key));
            if (($typeId === null || $t === $typeId) && ($slotId === null || $s === $slotId)) {
                $sum += $day === null ? array_sum($days) : ($days[$day] ?? 0);
            }
        }

        return $sum;
    }

    public static function cents(array $entry, ?int $typeId = null, ?int $slotId = null): int
    {
        $sum = 0;
        foreach ($entry['cents'] as $key => $c) {
            [$t, $s] = array_map('intval', explode('|', $key));
            if (($typeId === null || $t === $typeId) && ($slotId === null || $s === $slotId)) {
                $sum += $c;
            }
        }

        return $sum;
    }
}
