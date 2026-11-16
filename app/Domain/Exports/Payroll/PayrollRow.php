<?php

namespace App\Domain\Exports\Payroll;

use App\Models\Employee;

/** One employee's month for payroll: counts per kind and the total amount (orders + mandatory). */
final readonly class PayrollRow
{
    public function __construct(
        public Employee $employee,
        public int $employeeMeals,   // Zamestnanecká strava (1,50 €), ordered
        public int $extraMeals,      // Dokupovaná strava (6,00 €)
        public int $mandatoryMeals,  // Povinná strava (automatic, from attendance)
        public int $totalCents,
    ) {}
}
