<?php

namespace App\Domain\Attendance;

use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Pairs WebVisitor personal numbers (ints) with app employees: osobné číslo without
 * leading zeros ("003" = 3). If two app employees normalise to the same number, the
 * number is a conflict and neither is updated (listed in the admin).
 */
class EmployeeMatcher
{
    /** @var Collection<string, Collection<int, Employee>>|null */
    private ?Collection $map = null;

    /** @return Collection<string, Collection<int, Employee>> */
    public function map(): Collection
    {
        return $this->map ??= Employee::query()->get()->groupBy(fn (Employee $e) => Employee::attendanceNumber($e->osobne_cislo));
    }

    public function find(string $number): ?Employee
    {
        $hits = $this->map()[Employee::attendanceNumber($number)] ?? collect();

        return $hits->count() === 1 ? $hits->first() : null;
    }

    public function isConflict(string $number): bool
    {
        return ($this->map()[Employee::attendanceNumber($number)] ?? collect())->count() > 1;
    }

    public function isConflictFor(Employee $employee): bool
    {
        return $this->isConflict($employee->osobne_cislo);
    }
}
