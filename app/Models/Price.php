<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A price version. The version with the latest valid_from <= meal date applies.
 */
class Price extends Model
{
    use LogsActivity;

    protected $fillable = ['price_type_id', 'amount_cents', 'valid_from', 'created_by_user_id'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'valid_from' => LocalDate::class,
        ];
    }

    public function priceType(): BelongsTo
    {
        return $this->belongsTo(PriceType::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('prices');
    }
}
