<?php

namespace App\Models;

use App\Enums\PricingMode;
use App\Support\LocalTime;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Skupina stravníkov („8 h“, „12 h“, „Brigádnici“). Its pricing mode is versioned in
 * meal_group_modes; employees join via employee_group_memberships (both by date).
 */
class MealGroup extends Model
{
    use LogsActivity;

    protected $fillable = ['name', 'default_tickets', 'is_active'];

    protected function casts(): array
    {
        return ['default_tickets' => 'integer', 'is_active' => 'boolean'];
    }

    public function modes(): HasMany
    {
        return $this->hasMany(MealGroupMode::class)->orderBy('effective_from');
    }

    public function monthCounts(): HasMany
    {
        return $this->hasMany(TicketMonthCount::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(EmployeeGroupMembership::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Mode on a date (no history row yet = daily). Uses the loaded `modes` relation if present. */
    public function modeOn(DateTimeInterface|string $date): PricingMode
    {
        $ymd = LocalTime::date($date)->toDateString();
        $mode = $this->modes->filter(fn (MealGroupMode $m) => $m->effective_from->toDateString() <= $ymd)->last();

        return $mode?->mode ?? PricingMode::Daily;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'default_tickets', 'is_active'])->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('tickets');
    }
}
