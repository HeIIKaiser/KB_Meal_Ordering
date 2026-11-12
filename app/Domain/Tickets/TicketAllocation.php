<?php

namespace App\Domain\Tickets;

use App\Enums\OrderStatus;
use App\Enums\PricingMode;
use App\Models\Employee;
use App\Models\MealGroup;
use App\Models\Order;
use App\Models\TicketMonthCount;
use App\Models\TicketOverride;

/**
 * How many tickets an employee has in a month:
 *   individual override → the group's count for that month → the group's default.
 * Tickets therefore exist for every month (also the next one) without any cron job.
 */
class TicketAllocation
{
    /** Null when the employee has no ticket-mode day in that month. */
    public function allocation(Employee $employee, string $month, ?EmployeeCalendar $calendar = null): ?int
    {
        $group = ($calendar ?? EmployeeCalendar::for($employee))->ticketGroupFor($month);
        if (! $group) {
            return null;
        }

        $override = TicketOverride::query()->where('employee_id', $employee->id)->where('month', $month)->value('tickets');

        return $override ?? $this->groupCount($group, $month);
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
