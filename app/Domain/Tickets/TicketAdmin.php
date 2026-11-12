<?php

namespace App\Domain\Tickets;

use App\Models\Employee;
use App\Models\EmployeeGroupMembership;
use App\Models\TicketMonthCount;
use App\Models\TicketOverride;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Monthly ticket counts (per group) and individual overrides. Every change reprices
 * the affected employees' orders in that month right away.
 */
class TicketAdmin
{
    public function __construct(
        private Repricer $repricer,
        private ClosedMonths $closedMonths,
    ) {}

    /**
     * @param  array<int, int>  $groupCounts  group id → tickets (saved for the month)
     * @param  array<int, int|null>  $overrides  employee id → tickets, null = remove override (use the group count)
     */
    public function save(string $month, array $groupCounts, array $overrides, User $by, string $source = TicketOverride::SOURCE_MANUAL, string $cause = 'allocation'): RepriceResult
    {
        if (! $by->is_active || ! $by->isAdmin()) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($month, $groupCounts, $overrides, $by, $source, $cause) {
            $this->closedMonths->assertOpen($month);
            $affected = collect();

            $saved = TicketMonthCount::query()->where('month', $month)->pluck('tickets', 'meal_group_id');
            foreach ($groupCounts as $groupId => $tickets) {
                if (($saved[$groupId] ?? null) === $tickets) {
                    continue;
                }
                TicketMonthCount::query()->updateOrCreate(['meal_group_id' => $groupId, 'month' => $month], ['tickets' => $tickets]);
                activity('tickets')->causedBy($by)->event('month_count_set')
                    ->withProperties(['month' => $month, 'meal_group_id' => $groupId, 'old' => $saved[$groupId] ?? null, 'tickets' => $tickets])
                    ->log('Lístky na '.Month::label($month).': skupina #'.$groupId.' → '.$tickets);
                $affected = $affected->merge(EmployeeGroupMembership::query()->where('meal_group_id', $groupId)->pluck('employee_id'));
            }

            $existing = TicketOverride::query()->where('month', $month)->whereIn('employee_id', array_keys($overrides))->get()->keyBy('employee_id');
            foreach ($overrides as $employeeId => $tickets) {
                $old = $existing[$employeeId] ?? null;
                if ($old?->tickets === $tickets && ($tickets === null || $old->source === $source)) {
                    continue;
                }
                if ($tickets === null) {
                    if (! $old) {
                        continue;
                    }
                    $old->delete();
                } else {
                    TicketOverride::query()->updateOrCreate(['employee_id' => $employeeId, 'month' => $month], ['tickets' => $tickets, 'source' => $source]);
                }
                activity('tickets')->causedBy($by)->event('override_set')
                    ->withProperties(['month' => $month, 'employee_id' => $employeeId, 'old' => $old?->tickets, 'tickets' => $tickets, 'source' => $source])
                    ->log('Lístky na '.Month::label($month).' pre zamestnanca #'.$employeeId.': '.($tickets ?? 'podľa skupiny'));
                $affected->push($employeeId);
            }

            $result = new RepriceResult;
            $employees = Employee::query()->whereIn('id', $affected->unique())->orderBy('id')->lockForUpdate()->get();
            foreach ($employees as $employee) {
                $result->merge($this->repricer->reprice($employee, $month, $cause, $by));
            }

            return $result;
        });
    }

    public function preview(string $month, array $groupCounts, array $overrides, User $by, string $source = TicketOverride::SOURCE_MANUAL, string $cause = 'allocation'): RepriceResult
    {
        return Simulation::run(fn () => $this->save($month, $groupCounts, $overrides, $by, $source, $cause));
    }
}
