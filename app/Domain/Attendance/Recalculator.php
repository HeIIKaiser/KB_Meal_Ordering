<?php

namespace App\Domain\Attendance;

use App\Domain\Ordering\PriceResolver;
use App\Domain\Tickets\EmployeeCalendar;
use App\Domain\Tickets\Month;
use App\Domain\Tickets\Repricer;
use App\Domain\Tickets\RepriceResult;
use App\Models\AttendanceResult;
use App\Models\Employee;
use App\Models\MandatoryMeal;
use App\Models\PriceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Applies attendance to one employee and month: stores the estimate (or the final
 * result), replaces the automatic mandatory charges and reprices ticket-mode orders.
 * Callers check that the month is open. Runs in a transaction with the employee row
 * locked, like every other price change.
 */
class Recalculator
{
    public function __construct(
        private AttendanceCalculator $calculator,
        private Repricer $repricer,
        private PriceResolver $prices,
        private EmployeeMatcher $matcher,
    ) {}

    /** Live estimate after a sync / the nightly recompute. A finalised month keeps its final values. */
    public function estimate(Employee $employee, string $month, ?Model $causer = null): RepriceResult
    {
        return DB::transaction(function () use ($employee, $month, $causer) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->value('id');
            $calendar = EmployeeCalendar::for($employee);
            $e = $this->entitlementFor($employee, $month, false, $calendar);

            $result = AttendanceResult::query()->firstOrNew(['employee_id' => $employee->id, 'month' => $month]);
            if (! $e->hasData && ! $result->exists) {
                return new RepriceResult; // nothing synced for this person → fallbacks apply
            }
            $result->fill([
                'estimate_meals' => $e->hasData ? $e->tickets : null,
                'mandatory_estimate' => $e->hasData ? $e->mandatoryTotal() : null,
                'flags' => $e->flags(),
                'computed_at' => now(),
            ])->save();

            if (! $result->isFinal()) {
                $this->replaceMandatory($employee, $month, $e->hasData ? $e->mandatory : [], MandatoryMeal::SOURCE_ESTIMATE);
            }

            return $this->repricer->reprice($employee, $month, 'attendance', $causer, $calendar);
        });
    }

    /** „Finalizovať mesiac“: actual data only; stores the final values and charges, reprices. */
    public function finalize(Employee $employee, string $month, ?Model $by): RepriceResult
    {
        return DB::transaction(function () use ($employee, $month, $by) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->value('id');
            $calendar = EmployeeCalendar::for($employee);
            $estimate = $this->entitlementFor($employee, $month, false, $calendar);
            $final = $this->entitlementFor($employee, $month, true, $calendar);

            AttendanceResult::query()->updateOrCreate(['employee_id' => $employee->id, 'month' => $month], [
                'estimate_meals' => $estimate->tickets,
                'mandatory_estimate' => $estimate->mandatoryTotal(),
                'final_meals' => $final->tickets,
                'mandatory_final' => $final->mandatoryTotal(),
                'flags' => $final->flags(),
                'computed_at' => now(),
                'finalized_at' => now(),
                'finalized_by_user_id' => $by?->getKey(),
            ]);
            $this->replaceMandatory($employee, $month, $final->mandatory, MandatoryMeal::SOURCE_FINAL);

            return $this->repricer->reprice($employee, $month, 'attendance_final', $by, $calendar);
        });
    }

    public function entitlementFor(Employee $employee, string $month, bool $final, ?EmployeeCalendar $calendar = null): MonthEntitlement
    {
        if ($this->matcher->isConflictFor($employee)) {
            return new MonthEntitlement(null, [], [], false, null);
        }

        return $this->calculator->compute($employee, $month, $final, $calendar);
    }

    /** @param array<string, int> $perDay */
    private function replaceMandatory(Employee $employee, string $month, array $perDay, string $source): void
    {
        MandatoryMeal::query()->where('employee_id', $employee->id)
            ->whereBetween('date', [Month::first($month)->toDateString(), Month::last($month)->toDateString()])
            ->delete();

        $type = PriceType::byCode(PriceType::DONATED);
        foreach ($perDay as $date => $count) {
            MandatoryMeal::query()->create([
                'employee_id' => $employee->id,
                'date' => $date,
                'count' => $count,
                'unit_price_cents' => $this->prices->amountFor($type, $date),
                'source' => $source,
            ]);
        }
    }
}
