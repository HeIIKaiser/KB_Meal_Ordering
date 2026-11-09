<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A manual lock of one slot on one date (kitchen/admin "Uzamknúť"). Unlock = delete. Audited by SlotLockService. */
class SlotLock extends Model
{
    protected $fillable = ['date', 'meal_slot_id', 'locked_by_user_id', 'locked_at'];

    protected function casts(): array
    {
        return [
            'date' => LocalDate::class,
            'locked_at' => 'immutable_datetime',
        ];
    }

    public function mealSlot(): BelongsTo
    {
        return $this->belongsTo(MealSlot::class);
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by_user_id');
    }
}
