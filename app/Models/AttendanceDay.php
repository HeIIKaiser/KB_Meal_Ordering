<?php

namespace App\Models;

use App\Casts\LocalDate;
use Illuminate\Database\Eloquent\Model;

/** One synced WebVisitor day of one person (all employments merged). Written by AttendanceSync only. */
class AttendanceDay extends Model
{
    public $timestamps = false;

    protected $fillable = ['osobne_cislo', 'date', 'planned_seconds', 'plan_name', 'total_seconds', 'absences', 'status', 'month_locked', 'synced_at'];

    protected function casts(): array
    {
        return [
            'date' => LocalDate::class,
            'planned_seconds' => 'integer',
            'total_seconds' => 'integer',
            'absences' => 'array',
            'status' => 'integer',
            'month_locked' => 'boolean',
            'synced_at' => 'immutable_datetime',
        ];
    }
}
