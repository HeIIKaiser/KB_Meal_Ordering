<?php

use App\Models\Employee;
use App\Models\MealSlot;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => (new ReferenceDataSeeder)->run())
    ->in('Feature');

// Real multi-process tests need committed data, so no wrapping transaction here.
pest()->extend(TestCase::class)
    ->use(DatabaseMigrations::class)
    ->beforeEach(fn () => (new ReferenceDataSeeder)->run())
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function lunch(): MealSlot
{
    return MealSlot::query()->where('code', MealSlot::LUNCH)->firstOrFail();
}

function dinner(): MealSlot
{
    return MealSlot::query()->where('code', MealSlot::DINNER)->firstOrFail();
}

/** Freeze "now" at a LOCAL (Europe/Bratislava) wall-clock time. */
function travelToLocal(string $localDateTime): CarbonImmutable
{
    $instant = CarbonImmutable::parse($localDateTime, LocalTime::zone());
    test()->travelTo($instant);

    return $instant;
}

/** Create a menu with $count active options and return the options (Menu 1..n). */
function menuItems(string $date, ?MealSlot $slot = null, int $count = 2): Collection
{
    $menu = Menu::factory()->withItems($count)->create([
        'date' => $date,
        'meal_slot_id' => ($slot ?? lunch())->id,
    ]);

    return $menu->items()->get();
}

function employee(array $attributes = []): Employee
{
    return Employee::factory()->create($attributes);
}

function firstItem(string $date, ?MealSlot $slot = null): MenuItem
{
    return menuItems($date, $slot)->first();
}
