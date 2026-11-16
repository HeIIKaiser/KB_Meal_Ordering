<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Automatic charge of mandatory mode for one day (count × employee price). Written by Attendance\Recalculator only. */
class MandatoryMeal extends Model
{
    public const SOURCE_ESTIMATE = 'estimate';

    public const SOURCE_FINAL = 'final';

    protected $fillable = ['employee_id', 'date', 'count', 'unit_price_cents', 'source'];

    protected function casts(): array
    {
        return ['date' => LocalDate::class, 'count' => 'integer', 'unit_price_cents' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
