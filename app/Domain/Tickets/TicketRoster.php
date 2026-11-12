<?php

namespace App\Domain\Tickets;

use App\Enums\PricingMode;
use App\Models\Employee;
use App\Models\EmployeeGroupMembership;
use App\Models\MealGroup;
use Illuminate\Support\Collection;

/**
 * Which employees are in ticket mode in a month, with their calendars (batch-loaded:
 * a constant number of queries for the whole company).
 */
class TicketRoster
{
    /** @return Collection<int, MealGroup> groups in ticket mode on at least one day of the month */
    public function ticketGroups(string $month): Collection
    {
        return MealGroup::query()->with('modes')->orderBy('name')->get()
            ->filter(fn (MealGroup $g) => $this->groupHasTicketsIn($g, $month))
            ->values();
    }

    public function groupHasTicketsIn(MealGroup $group, string $month): bool
    {
        if ($group->modeOn(Month::first($month)) === PricingMode::Tickets) {
            return true;
        }

        return $group->modes->contains(fn ($m) => $m->mode === PricingMode::Tickets
            && $m->effective_from->format('Y-m') === $month);
    }

    /**
     * Employees with at least one ticket-mode day in the month (alphabetical).
     *
     * @return Collection<int, array{employee: Employee, calendar: EmployeeCalendar, group: MealGroup}>
     */
    public function employees(string $month, bool $withInactive = true): Collection
    {
        $groupIds = $this->ticketGroups($month)->pluck('id');
        if ($groupIds->isEmpty()) {
            return collect();
        }

        $employeeIds = EmployeeGroupMembership::query()->whereIn('meal_group_id', $groupIds)
            ->where('effective_from', '<=', Month::last($month)->toDateString())
            ->distinct()->pluck('employee_id');

        $memberships = EmployeeGroupMembership::query()->whereIn('employee_id', $employeeIds)->with('group.modes')->get()->groupBy('employee_id');

        return Employee::query()->whereIn('id', $employeeIds)
            ->when(! $withInactive, fn ($q) => $q->where('is_active', true))
            ->alphabetical()->get()
            ->map(function (Employee $e) use ($memberships, $month) {
                $calendar = EmployeeCalendar::fromMemberships($e, $memberships[$e->id] ?? collect());
                $group = $calendar->ticketGroupFor($month);

                return $group ? ['employee' => $e, 'calendar' => $calendar, 'group' => $group] : null;
            })
            ->filter()
            ->values();
    }
}
