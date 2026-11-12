<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A month (YYYY-MM) closed after the final ticket import: no changes to its meals any more. */
class ClosedMonth extends Model
{
    protected $fillable = ['month', 'closed_at', 'closed_by_user_id'];

    protected function casts(): array
    {
        return ['closed_at' => 'immutable_datetime'];
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }
}
