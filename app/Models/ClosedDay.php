<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A date (or date + slot when meal_slot_id is set) on which no meals are served. */
class ClosedDay extends Model
{
    use LogsActivity;

    protected $fillable = ['date', 'meal_slot_id', 'note', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['date' => LocalDate::class];
    }

    public function mealSlot(): BelongsTo
    {
        return $this->belongsTo(MealSlot::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('settings');
    }
}
