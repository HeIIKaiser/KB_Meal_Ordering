<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A fixed price (pricing mode "fixed") for a group or one person, for all meals
 * (meal_slot_id NULL) or one meal, valid for meal dates from valid_from on.
 * Resolved by App\Domain\Ordering\FixedPriceResolver; snapshotted on the order.
 */
class FixedPrice extends Model
{
    use LogsActivity;

    protected $fillable = ['meal_group_id', 'employee_id', 'meal_slot_id', 'amount_cents', 'valid_from', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'valid_from' => LocalDate::class];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(MealGroup::class, 'meal_group_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function mealSlot(): BelongsTo
    {
        return $this->belongsTo(MealSlot::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['meal_group_id', 'employee_id', 'meal_slot_id', 'amount_cents', 'valid_from'])
            ->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('prices');
    }
}
