<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Log of bridge calls: received months (kind data) and bridge status reports (kind status). */
class AttendanceSync extends Model
{
    public $timestamps = false;

    protected $table = 'attendance_syncs';

    protected $fillable = ['kind', 'month', 'ok', 'message', 'employees', 'days', 'unmatched', 'late_changes', 'ip', 'received_at'];

    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'employees' => 'integer',
            'days' => 'integer',
            'unmatched' => 'array',
            'late_changes' => 'array',
            'received_at' => 'immutable_datetime',
        ];
    }
}
