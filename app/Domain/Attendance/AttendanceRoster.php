<?php

namespace App\Domain\Attendance;

use App\Domain\Tickets\EmployeeCalendar;
use App\Domain\Tickets\Month;
use App\Enums\PricingMode;
use App\Models\Employee;
use App\Models\EmployeeGroupMembership;
use App\Models\MealGroup;
use Illuminate\Support\Collection;

/** Employees whose meals depend on attendance in a month: any day in ticket or mandatory mode. */
class AttendanceRoster
{
    /**
     * @return Collection<int, array{employee: Employee, calendar: EmployeeCalendar, modes: list<PricingMode>}>
     */
    public function employees(string $month): Collection
    {
        $groupIds = MealGroup::query()->with('modes')->get()
            ->filter(fn (MealGroup $g) => $g->modes->contains(fn ($m) => $m->mode !== PricingMode::Daily))
            ->pluck('id');
        if ($groupIds->isEmpty()) {
            return collect();
        }

        $employeeIds = EmployeeGroupMembership::query()->whereIn('meal_group_id', $groupIds)
            ->where('effective_from', '<=', Month::last($month)->toDateString())
            ->distinct()->pluck('employee_id');
        $memberships = EmployeeGroupMembership::query()->whereIn('employee_id', $employeeIds)->with('group.modes')->get()->groupBy('employee_id');

        return Employee::query()->whereIn('id', $employeeIds)->alphabetical()->get()
            ->map(function (Employee $e) use ($memberships, $month) {
                $calendar = EmployeeCalendar::fromMemberships($e, $memberships[$e->id] ?? collect());
                $modes = array_values(array_filter($calendar->modesIn($month), fn ($m) => $m !== PricingMode::Daily));

                return $modes ? ['employee' => $e, 'calendar' => $calendar, 'modes' => $modes] : null;
            })
            ->filter()->values();
    }
}
