<?php

namespace App\Domain\Attendance;

use App\Domain\Tickets\EmployeeCalendar;
use App\Domain\Tickets\Month;
use App\Enums\PricingMode;
use App\Models\Employee;
use App\Models\EmployeeGroupMembership;
use App\Models\MealGroup;
use Illuminate\Support\Collection;

/** Employees whose meals depend on attendance in a month: any day in ticket or mandatory mode (never fixed). */
class AttendanceRoster
{
    /** Modes whose meals depend on attendance. */
    public const MODES = [PricingMode::Tickets, PricingMode::Mandatory];

    /**
     * @return Collection<int, array{employee: Employee, calendar: EmployeeCalendar, modes: list<PricingMode>}>
     */
    public function employees(string $month): Collection
    {
        $groupIds = MealGroup::query()->with('modes')->get()
            ->filter(fn (MealGroup $g) => $g->modes->contains(fn ($m) => in_array($m->mode, self::MODES, true)))
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
                $modes = array_values(array_filter($calendar->modesIn($month), fn ($m) => in_array($m, self::MODES, true)));

                return $modes ? ['employee' => $e, 'calendar' => $calendar, 'modes' => $modes] : null;
            })
            ->filter()->values();
    }
}
