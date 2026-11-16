<?php

namespace App\Domain\Ordering;

use App\Domain\Tickets\EmployeeCalendar;
use App\Models\Employee;
use App\Models\FixedPrice;
use App\Models\MealSlot;
use App\Support\LocalTime;
use DateTimeInterface;

/**
 * Fixed price of one meal (pricing mode "fixed", docs/CATEGORIES.md §3). First match:
 *   person + this meal → person + all meals → group + this meal → group + all meals,
 * each the version with the latest valid_from on or before the MEAL date.
 */
class FixedPriceResolver
{
    public function find(Employee $employee, DateTimeInterface|string $date, MealSlot $slot, ?EmployeeCalendar $calendar = null): ?FixedPrice
    {
        $ymd = LocalTime::date($date)->toDateString();
        $group = ($calendar ?? EmployeeCalendar::for($employee))->groupOn($ymd);

        $candidates = FixedPrice::query()
            ->where(fn ($q) => $q->where('employee_id', $employee->id)->when($group, fn ($q) => $q->orWhere('meal_group_id', $group->id)))
            ->where('valid_from', '<=', $ymd)
            ->where(fn ($q) => $q->whereNull('meal_slot_id')->orWhere('meal_slot_id', $slot->id))
            ->orderByDesc('valid_from')->orderByDesc('id')
            ->get();

        foreach ([[true, true], [true, false], [false, true], [false, false]] as [$person, $specific]) {
            $hit = $candidates->first(fn (FixedPrice $p) => ($person ? $p->employee_id === $employee->id : $p->employee_id === null)
                && ($specific ? $p->meal_slot_id === $slot->id : $p->meal_slot_id === null));
            if ($hit) {
                return $hit;
            }
        }

        return null;
    }

    public function amount(Employee $employee, DateTimeInterface|string $date, MealSlot $slot, ?EmployeeCalendar $calendar = null): int
    {
        return $this->find($employee, $date, $slot, $calendar)?->amount_cents
            ?? throw new OrderingException('Pre tohto zamestnanca nie je nastavená pevná cena. Kontaktujte administrátora.', 'no_fixed_price');
    }
}
