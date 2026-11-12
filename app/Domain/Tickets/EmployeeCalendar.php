<?php

namespace App\Domain\Tickets;

use App\Enums\PricingMode;
use App\Models\Employee;
use App\Models\EmployeeGroupMembership;
use App\Models\MealGroup;
use App\Support\LocalTime;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * One employee's group and pricing mode by date, from the membership and group-mode
 * histories (loaded once). Mode of a meal = mode of the employee's group on the meal
 * date; no group = daily.
 */
final class EmployeeCalendar
{
    /** @param Collection<int, EmployeeGroupMembership> $memberships sorted by effective_from */
    private function __construct(
        public readonly Employee $employee,
        private Collection $memberships,
    ) {}

    public static function for(Employee $employee): self
    {
        $memberships = EmployeeGroupMembership::query()
            ->where('employee_id', $employee->id)
            ->with('group.modes')
            ->orderBy('effective_from')
            ->get();

        return new self($employee, $memberships);
    }

    /** @param Collection<int, EmployeeGroupMembership> $memberships this employee's rows with group.modes loaded */
    public static function fromMemberships(Employee $employee, Collection $memberships): self
    {
        return new self($employee, $memberships->sortBy(fn ($m) => $m->effective_from->toDateString())->values());
    }

    public function groupOn(DateTimeInterface|string $date): ?MealGroup
    {
        $ymd = LocalTime::date($date)->toDateString();

        return $this->memberships
            ->filter(fn (EmployeeGroupMembership $m) => $m->effective_from->toDateString() <= $ymd)
            ->last()?->group;
    }

    public function modeOn(DateTimeInterface|string $date): PricingMode
    {
        return $this->groupOn($date)?->modeOn($date) ?? PricingMode::Daily;
    }

    /**
     * The group whose ticket count applies in a month: the employee's group on the last
     * day of the month on which they are in ticket mode. Null = no ticket days that month.
     */
    public function ticketGroupFor(string $month): ?MealGroup
    {
        if ($this->memberships->isEmpty()) {
            return null;
        }

        for ($day = Month::last($month), $first = Month::first($month); $day >= $first; $day = $day->subDay()) {
            if ($this->modeOn($day) === PricingMode::Tickets) {
                return $this->groupOn($day);
            }
        }

        return null;
    }

    /** Modes used on the days of a month (for "zmiesaný" in exports). @return list<PricingMode> */
    public function modesIn(string $month): array
    {
        $modes = [];
        for ($day = Month::first($month), $last = Month::last($month); $day <= $last; $day = $day->addDay()) {
            $modes[$this->modeOn($day)->value] = $this->modeOn($day);
        }

        return array_values($modes);
    }

    /** The membership row in effect on a date (to show/edit the group). */
    public function membershipOn(DateTimeInterface|string $date): ?EmployeeGroupMembership
    {
        $ymd = LocalTime::date($date)->toDateString();

        return $this->memberships->filter(fn ($m) => $m->effective_from->toDateString() <= $ymd)->last();
    }

    /** @return Collection<int, EmployeeGroupMembership> */
    public function memberships(): Collection
    {
        return $this->memberships;
    }

    public function today(): ?MealGroup
    {
        return $this->groupOn(LocalTime::today());
    }
}
