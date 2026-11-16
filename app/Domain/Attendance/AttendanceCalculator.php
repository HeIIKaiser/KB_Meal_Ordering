<?php

namespace App\Domain\Attendance;

use App\Domain\Tickets\EmployeeCalendar;
use App\Domain\Tickets\Month;
use App\Enums\PricingMode;
use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Applies the meal rule to one employee's synced days of a month (docs/ATTENDANCE.md §5).
 *
 * Estimate (open month, §5.1): future day (incl. today) → planned meals; past day with
 * work or an absence → actual; past planned day with nothing filled in → planned; extra
 * shift on an unplanned day → actual.
 * Final (§5.2): actual data only; a planned day with nothing filled in = 0 and flagged.
 *
 * Only days in ticket or mandatory mode count (mode of the employee's group on that day).
 */
class AttendanceCalculator
{
    public function __construct(private MealRule $rule) {}

    /** @param Collection<string, AttendanceDay>|null $days keyed by Y-m-d; null = load */
    public function compute(Employee $employee, string $month, bool $final, ?EmployeeCalendar $calendar = null, ?Collection $days = null, ?CarbonImmutable $today = null): MonthEntitlement
    {
        $calendar ??= EmployeeCalendar::for($employee);
        $days ??= $this->days($employee, $month);
        $today = ($today ?? LocalTime::today())->toDateString();
        $cap = $employee->max_meals_per_day;

        $tickets = null;
        $mandatory = [];
        $unfilled = [];
        $locked = $days->isEmpty() ? null : $days->every(fn (AttendanceDay $d) => $d->month_locked);

        for ($day = Month::first($month), $last = Month::last($month); $day <= $last; $day = $day->addDay()) {
            $mode = $calendar->modeOn($day);
            if ($mode === PricingMode::Daily) {
                continue;
            }
            $result = $this->day($days[$day->toDateString()] ?? null, $day->toDateString(), $today, $final, $cap);
            if ($result->basis === DayResult::UNFILLED || ($result->basis === DayResult::PLAN && $day->toDateString() < $today)) {
                $unfilled[] = $day->toDateString();
            }

            if ($mode === PricingMode::Tickets) {
                $tickets = ($tickets ?? 0) + $result->meals;
            } elseif ($mode === PricingMode::Mandatory && $result->meals > 0) {
                $n = $calendar->groupOn($day)?->mandatory_per_day ?? 2;
                $mandatory[$day->toDateString()] = $cap === null ? $n : min($n, $cap);
            }
        }

        return new MonthEntitlement($tickets, $mandatory, $unfilled, $days->isNotEmpty(), $locked);
    }

    public function day(?AttendanceDay $d, string $date, string $today, bool $final, ?int $cap): DayResult
    {
        if (! $d) {
            return new DayResult($date, 0, DayResult::NONE);
        }
        $filled = $d->total_seconds > 0 || ! empty($d->absences);
        $planned = $d->planned_seconds > 0;

        if ($final) {
            if ($filled) {
                return new DayResult($date, $this->rule->meals($d->total_seconds, $cap), DayResult::ACTUAL);
            }

            return new DayResult($date, 0, $planned && $date < $today ? DayResult::UNFILLED : DayResult::NONE);
        }

        if ($date >= $today || (! $filled && $planned)) {
            return new DayResult($date, $this->rule->meals($d->planned_seconds, $cap), $planned ? DayResult::PLAN : DayResult::NONE);
        }

        return new DayResult($date, $filled ? $this->rule->meals($d->total_seconds, $cap) : 0, $filled ? DayResult::ACTUAL : DayResult::NONE);
    }

    /** @return Collection<string, AttendanceDay> */
    public function days(Employee $employee, string $month): Collection
    {
        return AttendanceDay::query()
            ->where('osobne_cislo', Employee::attendanceNumber($employee->osobne_cislo))
            ->whereBetween('date', [Month::first($month)->toDateString(), Month::last($month)->toDateString()])
            ->get()
            ->keyBy(fn (AttendanceDay $d) => $d->date->toDateString());
    }
}
