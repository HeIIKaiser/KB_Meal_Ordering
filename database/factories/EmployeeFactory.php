<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<Employee> */
class EmployeeFactory extends Factory
{
    protected static ?string $pinHash = null;

    public function definition(): array
    {
        return [
            'osobne_cislo' => (string) fake()->unique()->numberBetween(1000, 99999),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'department' => fake()->randomElement(['Kuchyňa', 'Recepcia', 'Wellness', 'Údržba', 'Upratovanie', 'Lekári', 'Administratíva']),
            'pin_hash' => static::$pinHash ??= Hash::make('1234'),
            'is_active' => true,
            'remember_token' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
