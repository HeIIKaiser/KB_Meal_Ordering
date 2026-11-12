<?php

use App\Domain\Ordering\Actor;
use App\Domain\Ordering\OrderService;
use App\Enums\PricingMode;
use App\Models\Employee;
use App\Models\EmployeeGroupMembership;
use App\Models\MealGroup;
use App\Models\MealGroupMode;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;

/** A group in ticket mode from $from (shared helpers for the ticket-mode tests). */
function ticketGroup(int $tickets = 20, string $from = '2026-11-01', string $name = '12 h'): MealGroup
{
    $group = MealGroup::query()->create(['name' => $name, 'default_tickets' => $tickets, 'is_active' => true]);
    MealGroupMode::query()->create(['meal_group_id' => $group->id, 'mode' => PricingMode::Tickets, 'effective_from' => $from]);

    return $group;
}

function joinGroup(Employee $employee, ?MealGroup $group, string $from = '2026-11-01'): Employee
{
    EmployeeGroupMembership::query()->create(['employee_id' => $employee->id, 'meal_group_id' => $group?->id, 'effective_from' => $from]);

    return $employee;
}

/** Lunch Menu 1 on each date (created on demand). @return array<string, MenuItem> */
function lunchItemsFor(array $dates): array
{
    return collect($dates)->mapWithKeys(fn ($d) => [$d => firstItem($d, lunch())])->all();
}

/** Place orders as the employee (accepting 6 € where needed); returns them in order. */
function orderAll(Employee $employee, iterable $items): array
{
    $out = [];
    foreach ($items as $item) {
        $out[] = app(OrderService::class)->place($employee, $item, Actor::employee($employee), acceptExtra: true);
    }

    return $out;
}

/** Order prices of an employee, sorted by date/slot/id: [cents, …] */
function prices(Employee $employee): array
{
    return Order::query()->where('employee_id', $employee->id)->active()
        ->join('meal_slots', 'meal_slots.id', '=', 'orders.meal_slot_id')
        ->orderBy('orders.date')->orderBy('meal_slots.sort')->orderBy('orders.id')
        ->pluck('orders.unit_price_cents')->map(fn ($c) => (int) $c)->all();
}

function adminUser(): User
{
    return User::factory()->admin()->create();
}
