<?php

namespace App\Domain\Exports\Invoice;

use App\Domain\Exports\MonthlyData;
use App\Domain\Tickets\Month;
use App\Enums\OrderStatus;
use App\Models\MandatoryMeal;
use App\Models\MealSlot;
use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * Invoiced people (group settlement = invoice on the last day of the month) with their
 * orders of a month. Totals equal the monthly matrix (both come from the same orders
 * and mandatory charges).
 */
final class InvoiceData
{
    /** @param Collection<int, InvoiceRow> $rows */
    private function __construct(
        public readonly string $month,
        public readonly bool $closed,
        public readonly Collection $rows,
    ) {}

    public static function for(string $month): self
    {
        $monthly = MonthlyData::for($month);
        $invoiced = $monthly->employees->filter(fn ($e) => $e['settlement'] === 'faktura' && MonthlyData::totalCents($e) > 0);
        $range = [Month::first($month)->toDateString(), Month::last($month)->toDateString()];

        $orders = Order::query()
            ->whereIn('employee_id', $invoiced->map(fn ($e) => $e['employee']->id))
            ->where('status', OrderStatus::Active->value)
            ->whereBetween('date', $range)
            ->with(['mealSlot', 'menuItem'])
            ->get()
            ->sortBy(fn (Order $o) => [$o->date->toDateString(), $o->mealSlot->sort, $o->id])
            ->groupBy('employee_id');
        $mandatory = MandatoryMeal::query()->whereIn('employee_id', $invoiced->map(fn ($e) => $e['employee']->id))
            ->whereBetween('date', $range)->get()->groupBy('employee_id');

        $rows = $invoiced->map(function (array $e) use ($orders, $mandatory) {
            $mine = $orders[$e['employee']->id] ?? collect();
            $lines = $mine->map(fn (Order $o) => [
                'date' => $o->date, 'meal' => $o->mealSlot->name,
                'menu' => $o->menuItem->label().': '.$o->menuItem->name, 'cents' => $o->unit_price_cents,
            ]);
            foreach ($mandatory[$e['employee']->id] ?? [] as $m) {
                for ($i = 0; $i < $m->count; $i++) {
                    $lines->push(['date' => $m->date, 'meal' => 'Povinná strava', 'menu' => 'podľa dochádzky', 'cents' => $m->unit_price_cents]);
                }
            }
            $lines = $lines->sortBy(fn ($l) => $l['date']->toDateString())->values();

            return new InvoiceRow(
                $e['employee'],
                $mine->filter(fn (Order $o) => $o->mealSlot->code === MealSlot::LUNCH)->count(),
                $mine->filter(fn (Order $o) => $o->mealSlot->code === MealSlot::DINNER)->count(),
                $mine->filter(fn (Order $o) => ! in_array($o->mealSlot->code, [MealSlot::LUNCH, MealSlot::DINNER], true))->count()
                    + MonthlyData::mandatoryCount($e),
                $lines->pluck('cents')->unique()->sort()->values()->all(),
                MonthlyData::totalCents($e),
                $lines,
            );
        })->values();

        return new self($month, $monthly->closed, $rows);
    }

    public function totalCents(): int
    {
        return $this->rows->sum(fn (InvoiceRow $r) => $r->totalCents);
    }
}
