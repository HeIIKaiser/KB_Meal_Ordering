<?php

namespace Database\Factories;

use App\Models\MealSlot;
use App\Models\Menu;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Menu> */
class MenuFactory extends Factory
{
    public function definition(): array
    {
        return [
            'date' => now()->toDateString(),
            'meal_slot_id' => fn () => MealSlot::query()->value('id'),
        ];
    }

    /** Add $count active items ("Menu 1", "Menu 2", …). */
    public function withItems(int $count = 2): static
    {
        return $this->afterCreating(function (Menu $menu) use ($count) {
            for ($i = 1; $i <= $count; $i++) {
                MenuItem::factory()->for($menu)->create(['number' => $i]);
            }
        });
    }
}
