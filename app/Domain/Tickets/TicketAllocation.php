<?php

namespace App\Domain\Tickets;

use App\Enums\OrderStatus;
use App\Enums\PricingMode;
use App\Models\AttendanceResult;
use App\Models\Employee;
use App\Models\MealGroup;
use App\Models\Order;
use App\Models\TicketMonthCount;
use App\Models\TicketOverride;

/**
 * How many tickets an employee has in a month — the first available value wins
 * (docs/ATTENDANCE.md §5.3):
 *   1. manual individual override
 *   2. final attendance result (after „Finalizovať mesiac“)
 *   3. override from the „Konečné lístky“ import
 *   4. temps (estimate_source = orders): the number of their orders — docs/CATEGORIES.md §2;
 *      otherwise the live attendance estimate (nightly sync)
 *   5. the group's count for that month
 *   6. the group's default
 * Tickets therefore exist for every month (also the next one) without any cron job.
 */
class TicketAllocation
{
    /**
     * Temps (group estimate_source = orders): while the month is open every order counts
     * as a ticket at 1,50 €; attendance decides at finalisation. Set to false for the
     * strict variant (6 € until attendance confirms the day) — docs/CATEGORIES.md §2.
     */
    public const ORDERS_ESTIMATE_AT_EMPLOYEE_PRICE = true;

    public const SOURCES = [
        'manual' => 'individuálne',
        'attendance_final' => 'dochádzka – konečné',
        'import' => 'import konečných lístkov',
        'orders_estimate' => 'objednávky (odhad)',
        'attendance_estimate' => 'dochádzka – odhad',
        'group_month' => 'skupina (mesiac)',
        'group_default' => 'skupina (predvolené)',
    ];

    /** Null when the employee has no ticket-mode day in that month. */
    public function allocation(Employee $employee, string $month, ?EmployeeCalendar $calendar = null): ?int
    {
        return $this->allocationWithSource($employee, $month, $calendar)['tickets'] ?? null;
    }

    /** @return array{tickets: int, source: string}|null */
    public function allocationWithSource(Employee $employee, string $month, ?EmployeeCalendar $calendar = null): ?array
    {
        $group = ($calendar ?? EmployeeCalendar::for($employee))->ticketGroupFor($month);
        if (! $group) {
            return null;
        }

        $override = TicketOverride::query()->where('employee_id', $employee->id)->where('month', $month)->first();
        $result = AttendanceResult::query()->where('employee_id', $employee->id)->where('month', $month)->first();
        $saved = TicketMonthCount::query()->where('meal_group_id', $group->id)->where('month', $month)->value('tickets');

        return match (true) {
            $override?->source === TicketOverride::SOURCE_MANUAL => ['tickets' => $override->tickets, 'source' => 'manual'],
            $result?->final_meals !== null => ['tickets' => $result->final_meals, 'source' => 'attendance_final'],
            $override !== null => ['tickets' => $override->tickets, 'source' => 'import'],
            $group->estimatesByOrders() => ['tickets' => self::ORDERS_ESTIMATE_AT_EMPLOYEE_PRICE ? $this->used($employee, $month) : 0, 'source' => 'orders_estimate'],
            $result?->estimate_meals !== null => ['tickets' => $result->estimate_meals, 'source' => 'attendance_estimate'],
            $saved !== null => ['tickets' => $saved, 'source' => 'group_month'],
            default => ['tickets' => $group->default_tickets, 'source' => 'group_default'],
        };
    }

    /** The group's count for the month, else its default. */
    public function groupCount(MealGroup $group, string $month): int
    {
        return TicketMonthCount::query()->where('meal_group_id', $group->id)->where('month', $month)->value('tickets')
            ?? $group->default_tickets;
    }

    /** Value to prefill on the monthly page: saved count, else last month's count, else the default. */
    public function prefill(MealGroup $group, string $month): int
    {
        return TicketMonthCount::query()->where('meal_group_id', $group->id)->where('month', $month)->value('tickets')
            ?? TicketMonthCount::query()->where('meal_group_id', $group->id)->where('month', Month::previous($month))->value('tickets')
            ?? $group->default_tickets;
    }

    /** Active ticket-mode orders of the employee with a meal date in the month. */
    public function used(Employee $employee, string $month): int
    {
        return Order::query()
            ->where('employee_id', $employee->id)
            ->where('status', OrderStatus::Active->value)
            ->where('pricing_mode', PricingMode::Tickets->value)
            ->whereBetween('date', [Month::first($month)->toDateString(), Month::last($month)->toDateString()])
            ->count();
    }

    /**
     * @return array{month: string, group: MealGroup, allocated: int, used: int, left: int, over: int}|null
     */
    public function summary(Employee $employee, string $month, ?EmployeeCalendar $calendar = null): ?array
    {
        $calendar ??= EmployeeCalendar::for($employee);
        $allocated = $this->allocation($employee, $month, $calendar);
        if ($allocated === null) {
            return null;
        }
        $used = $this->used($employee, $month);

        return [
            'month' => $month,
            'group' => $calendar->ticketGroupFor($month),
            'allocated' => $allocated,
            'used' => $used,
            'left' => max(0, $allocated - $used),
            'over' => max(0, $used - $allocated),
        ];
    }
}
