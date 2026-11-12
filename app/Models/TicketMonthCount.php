<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tickets per employee of a group in one month (month = "YYYY-MM"). */
class TicketMonthCount extends Model
{
    protected $fillable = ['meal_group_id', 'month', 'tickets'];

    protected function casts(): array
    {
        return ['tickets' => 'integer'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(MealGroup::class, 'meal_group_id');
    }
}
