<?php

namespace App\Models;

use App\Casts\LocalDate;
use App\Enums\PricingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A group's pricing mode from effective_from on. Written by MealGroupService only. */
class MealGroupMode extends Model
{
    protected $fillable = ['meal_group_id', 'mode', 'effective_from', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['mode' => PricingMode::class, 'effective_from' => LocalDate::class];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(MealGroup::class, 'meal_group_id');
    }
}
