<?php

namespace App\Domain\Attendance;

use App\Domain\Tickets\Month;
use App\Enums\OrderStatus;
use App\Models\MandatoryMeal;
use App\Models\Order;

/** € an employee pays for a month: active orders + automatic mandatory charges. */
final class MonthTotals
{
    public static function cents(int $employeeId, string $month): int
    {
        $range = [Month::first($month)->toDateString(), Month::last($month)->toDateString()];

        return (int) Order::query()->where('employee_id', $employeeId)->where('status', OrderStatus::Active->value)
            ->whereBetween('date', $range)->sum('unit_price_cents')
            + (int) MandatoryMeal::query()->where('employee_id', $employeeId)->whereBetween('date', $range)
                ->selectRaw('COALESCE(SUM(count * unit_price_cents), 0) as c')->value('c');
    }

    public static function mandatoryCount(int $employeeId, string $month): int
    {
        return (int) MandatoryMeal::query()->where('employee_id', $employeeId)
            ->whereBetween('date', [Month::first($month)->toDateString(), Month::last($month)->toDateString()])->sum('count');
    }
}
