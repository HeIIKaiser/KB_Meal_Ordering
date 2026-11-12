<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Individual ticket count of one employee for one month (manual, or from the month-end import). */
class TicketOverride extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_IMPORT = 'import';

    protected $fillable = ['employee_id', 'month', 'tickets', 'source'];

    protected function casts(): array
    {
        return ['tickets' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
