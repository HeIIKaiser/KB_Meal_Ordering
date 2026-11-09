<?php

namespace App\Domain\Exports;

use App\Enums\OrderStatus;
use App\Models\Employee;
use App\Models\MealSlot;
use App\Models\Order;
use App\Models\PriceType;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Per-employee monthly numbers for payroll / attendance matching: counts per
 * day × price type (× slot) and € sums from each order's snapshot price.
 * Active orders only. One grouped query for the whole month.
 *
 * Layout-independent — the XLSX layout lives in MonthlyMatrixXlsx.
 */
final class MonthlyData
{
    /**
     * @param  Collection<int, array{employee: Employee, counts: array<string, array<int, int>>, cents: array<string, int>}>  $employees
     *                                                                                                                                    counts keyed "typeId|slotId" → [day => n]; cents keyed "typeId|slotId"
     */
    private function __construct(
        public readonly CarbonImmutable $month,
        public readonly Collection $priceTypes,
        public readonly Collection $slots,
        public readonly Collection $employees,
    ) {}

    public static function for(string $yearMonth, bool $includeWithoutOrders = false): self
    {
        $month = LocalTime::date($yearMonth.'-01');
        $from = $month->toDateString();
        $to = $month->endOfMonth()->toDateString();

        $rows = Order::query()
            ->where('status', OrderStatus::Active->value)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('employee_id, date, meal_slot_id, price_type_id, COUNT(*) as n, SUM(unit_price_cents) as cents')
            ->groupBy('employee_id', 'date', 'meal_slot_id', 'price_type_id')
            ->toBase()
            ->get();

        $employees = Employee::query()
            ->where(fn ($q) => $q->whereIn('id', $rows->pluck('employee_id')->unique())
                ->when($includeWithoutOrders, fn ($q) => $q->orWhere('is_active', true)))
            ->alphabetical()
            ->get();

        $byEmployee = $rows->groupBy('employee_id');

        $data = $employees->map(function (Employee $e) use ($byEmployee) {
            $counts = [];
            $cents = [];
            foreach ($byEmployee[$e->id] ?? [] as $r) {
                $key = $r->price_type_id.'|'.$r->meal_slot_id;
                $day = (int) LocalTime::date($r->date)->format('j');
                $counts[$key][$day] = ($counts[$key][$day] ?? 0) + (int) $r->n;
                $cents[$key] = ($cents[$key] ?? 0) + (int) $r->cents;
            }

            return ['employee' => $e, 'counts' => $counts, 'cents' => $cents];
        })->values();

        return new self(
            $month,
            PriceType::query()->orderBy('sort')->orderBy('id')->get(),
            MealSlot::query()->ordered()->get(),
            $data,
        );
    }

    public function daysInMonth(): int
    {
        return $this->month->daysInMonth;
    }

    /** Count for one employee entry, optional type/slot/day filters (null = all). */
    public static function count(array $entry, ?int $typeId = null, ?int $slotId = null, ?int $day = null): int
    {
        $sum = 0;
        foreach ($entry['counts'] as $key => $days) {
            [$t, $s] = array_map('intval', explode('|', $key));
            if (($typeId === null || $t === $typeId) && ($slotId === null || $s === $slotId)) {
                $sum += $day === null ? array_sum($days) : ($days[$day] ?? 0);
            }
        }

        return $sum;
    }

    public static function cents(array $entry, ?int $typeId = null, ?int $slotId = null): int
    {
        $sum = 0;
        foreach ($entry['cents'] as $key => $c) {
            [$t, $s] = array_map('intval', explode('|', $key));
            if (($typeId === null || $t === $typeId) && ($slotId === null || $s === $slotId)) {
                $sum += $c;
            }
        }

        return $sum;
    }
}
