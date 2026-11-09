<?php

namespace Database\Seeders;

use App\Models\MealSlot;
use App\Models\Price;
use App\Models\PriceType;
use Illuminate\Database\Seeder;

/**
 * Reference data required in every environment (also production).
 * Idempotent: safe to run on every deploy — it never overwrites values an admin
 * has changed, it only creates what is missing.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        // OPEN (brief §13.2): cutoff times to be confirmed by Roman.
        MealSlot::query()->firstOrCreate(['code' => MealSlot::LUNCH], [
            'name' => 'Obed', 'name_accusative' => 'obed', 'sort' => 10,
            'cutoff_days_before' => 0, 'cutoff_time' => '09:00:00', 'is_active' => true,
        ]);
        MealSlot::query()->firstOrCreate(['code' => MealSlot::DINNER], [
            'name' => 'Večera', 'name_accusative' => 'večeru', 'sort' => 20,
            'cutoff_days_before' => 0, 'cutoff_time' => '14:00:00', 'is_active' => true,
        ]);

        $donated = PriceType::query()->firstOrCreate(['code' => PriceType::DONATED], [
            'name' => 'Darované', 'sort' => 10, 'per_slot_daily_limit' => 1,
        ]);
        $extra = PriceType::query()->firstOrCreate(['code' => PriceType::EXTRA], [
            'name' => 'Extra', 'sort' => 20, 'per_slot_daily_limit' => null, // null = setting max_extra_per_slot
        ]);

        if (! $donated->prices()->exists()) {
            Price::query()->create(['price_type_id' => $donated->id, 'amount_cents' => 150, 'valid_from' => '2000-01-01']);
        }
        if (! $extra->prices()->exists()) {
            Price::query()->create(['price_type_id' => $extra->id, 'amount_cents' => 600, 'valid_from' => '2000-01-01']);
        }
    }
}
