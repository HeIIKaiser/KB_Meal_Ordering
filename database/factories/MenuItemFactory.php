<?php

namespace Database\Factories;

use App\Models\Menu;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MenuItem> */
class MenuItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'menu_id' => Menu::factory(),
            'number' => 1,
            'name' => fake()->randomElement(['Rybie prsty, zemiaková kaša', 'Kuracie prsia na prírodno, ryža', 'Bryndzové halušky', 'Hovädzí guláš, knedľa', 'Zeleninové rizoto', 'Vyprážaný syr, hranolky']),
            'allergens' => '1,3,7',
            'is_active' => true,
        ];
    }
}
