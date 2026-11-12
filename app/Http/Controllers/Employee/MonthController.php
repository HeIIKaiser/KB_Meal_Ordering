<?php

namespace App\Http\Controllers\Employee;

use App\Domain\Tickets\ClosedMonths;
use App\Domain\Tickets\TicketAllocation;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** "Môj mesiac": the employee's active orders in a month with counts and € total. */
class MonthController extends Controller
{
    public function __invoke(Request $request): View
    {
        /** @var Employee $employee */
        $employee = Auth::guard('employee')->user();

        $month = $this->parseMonth((string) $request->query('mesiac')) ?? LocalTime::today()->startOfMonth();
        $end = $month->endOfMonth();

        $orders = $employee->orders()
            ->active()
            ->with(['menuItem', 'mealSlot', 'priceType'])
            ->whereBetween('date', [$month->toDateString(), $end->toDateString()])
            ->get()
            ->sortBy(fn ($o) => [$o->date->toDateString(), $o->mealSlot->sort, $o->priceType->sort])
            ->values();

        return view('employee.month', [
            'month' => $month,
            'orders' => $orders,
            'byDay' => $orders->groupBy(fn ($o) => $o->date->toDateString()),
            'byType' => $orders->groupBy(fn ($o) => $o->priceType->name)->map(fn ($g) => ['count' => $g->count(), 'cents' => $g->sum('unit_price_cents')]),
            'totalCents' => $orders->sum('unit_price_cents'),
            'prev' => $month->subMonth()->format('Y-m'),
            'next' => $month->addMonth()->format('Y-m'),
            'isCurrent' => $month->isSameMonth(LocalTime::today()),
            'tickets' => app(TicketAllocation::class)->summary($employee, $month->format('Y-m')),
            'closed' => app(ClosedMonths::class)->isClosed($month->format('Y-m')),
            'ended' => $end->lessThan(LocalTime::today()),
        ]);
    }

    private function parseMonth(string $value): ?CarbonImmutable
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', $value, $m) || (int) $m[2] < 1 || (int) $m[2] > 12) {
            return null;
        }

        return LocalTime::date("$value-01");
    }
}
