<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MealSlot extends Model
{
    use LogsActivity;

    public const LUNCH = 'obed';

    public const DINNER = 'vecera';

    protected $fillable = [
        'code', 'name', 'name_accusative', 'sort', 'cutoff_days_before', 'cutoff_time', 'has_soup', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'cutoff_days_before' => 'integer',
            'has_soup' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Lower-case form used in sentences ("na obed", "na večeru"). */
    public function accusative(): string
    {
        return $this->name_accusative ?: mb_strtolower($this->name);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('settings');
    }
}
