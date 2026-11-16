<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Meals from attendance for one employee and month: live estimate and (after Finalizovať) the final value. */
class AttendanceResult extends Model
{
    protected $fillable = [
        'employee_id', 'month', 'estimate_meals', 'final_meals', 'mandatory_estimate', 'mandatory_final',
        'flags', 'computed_at', 'finalized_at', 'finalized_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'estimate_meals' => 'integer',
            'final_meals' => 'integer',
            'mandatory_estimate' => 'integer',
            'mandatory_final' => 'integer',
            'flags' => 'array',
            'computed_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isFinal(): bool
    {
        return $this->finalized_at !== null;
    }
}
