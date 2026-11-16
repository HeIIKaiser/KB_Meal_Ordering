<?php

namespace App\Domain\Attendance;

use App\Domain\Tickets\ClosedMonths;
use App\Domain\Tickets\Month;
use App\Domain\Tickets\Simulation;
use App\Domain\Tickets\TicketAllocation;
use App\Enums\OrderStatus;
use App\Enums\PricingMode;
use App\Models\AttendanceResult;
use App\Models\Order;
use App\Models\TicketOverride;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * „Finalizovať mesiac“ (docs/ATTENDANCE.md §6.2): actual attendance only, stored as
 * attendance_final results + final mandatory charges, then repricing. The preview runs
 * the same code in a rolled-back transaction, so it always matches the result.
 */
class Finalizer
{
    public function __construct(
        private Recalculator $recalculator,
        private AttendanceRoster $roster,
        private ClosedMonths $closedMonths,
        private TicketAllocation $allocation,
    ) {}

    /** @return list<array<string, mixed>> one row per employee in ticket/mandatory mode */
    public function preview(string $month, User $by): array
    {
        return Simulation::run(fn () => $this->run($month, $by));
    }

    /** @return list<array<string, mixed>> */
    public function finalize(string $month, User $by): array
    {
        $rows = $this->run($month, $by);
        activity('tickets')->causedBy($by)->event('month_finalized')
            ->withProperties(['month' => $month, 'employees' => count($rows), 'changed' => collect($rows)->where('changed', true)->count()])
            ->log('Mesiac finalizovaný podľa dochádzky: '.Month::label($month));

        return $rows;
    }

    private function run(string $month, User $by): array
    {
        if (! $by->is_active || ! $by->isAdmin()) {
            throw new AuthorizationException;
        }
        $this->closedMonths->assertOpen($month);
        $rows = [];

        foreach ($this->roster->employees($month) as $r) {
            $e = $r['employee'];
            $before = AttendanceResult::query()->where('employee_id', $e->id)->where('month', $month)->first();
            $estimate = $before?->final_meals ?? $before?->estimate_meals;
            $mandatoryBefore = MonthTotals::mandatoryCount($e->id, $month);
            $centsBefore = MonthTotals::cents($e->id, $month);
            $override = TicketOverride::query()->where('employee_id', $e->id)->where('month', $month)->first();

            $repriced = $this->recalculator->finalize($e, $month, $by);
            $after = AttendanceResult::query()->where('employee_id', $e->id)->where('month', $month)->first();
            $centsAfter = MonthTotals::cents($e->id, $month);
            $tickets = in_array(PricingMode::Tickets, $r['modes'], true);
            $flags = $after?->flags ?? [];

            $rows[] = [
                'employee_id' => $e->id,
                'name' => $e->fullNameReversed(),
                'osobne_cislo' => $e->osobne_cislo,
                'modes' => array_map(fn ($m) => $m->value, $r['modes']),
                'tickets' => $tickets ? [$estimate, $after?->final_meals] : null,
                'allocation' => $tickets ? $this->allocation->allocationWithSource($e, $month, $r['calendar']) : null,
                'mandatory' => in_array(PricingMode::Mandatory, $r['modes'], true) ? [$mandatoryBefore, $after?->mandatory_final ?? 0] : null,
                'used' => Order::query()->where('employee_id', $e->id)->where('status', OrderStatus::Active->value)
                    ->where('pricing_mode', PricingMode::Tickets->value)
                    ->whereBetween('date', [Month::first($month)->toDateString(), Month::last($month)->toDateString()])->count(),
                'repriced' => $repriced->orders(),
                'cents' => [$centsBefore, $centsAfter],
                'flags' => [
                    'unfilled' => $flags['unfilled'] ?? [],
                    'not_locked' => (bool) ($flags['not_locked'] ?? false),
                    'no_data' => (bool) ($flags['no_data'] ?? false),
                    'manual_override' => $override?->source === TicketOverride::SOURCE_MANUAL,
                ],
                'changed' => ($tickets && $estimate !== $after?->final_meals)
                    || $mandatoryBefore !== ($after?->mandatory_final ?? 0) || $centsBefore !== $centsAfter,
            ];
        }

        return $rows;
    }
}
