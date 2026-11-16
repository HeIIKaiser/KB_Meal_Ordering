<?php

use App\Domain\Attendance\AttendanceSync;
use App\Enums\PricingMode;
use App\Models\MealGroup;
use App\Models\MealGroupMode;

/** Seconds from "H:MM". */
function hm(string $hm): int
{
    [$h, $m] = array_map('intval', explode(':', $hm));

    return $h * 3600 + $m * 60;
}

/**
 * Bridge-shaped payload for one month.
 * $people: osobne_cislo → [date => [planned "H:MM"|0, total "H:MM"|0, absences[]], …], plus optional 'locked' => bool.
 */
function attendancePayload(string $month, array $people): array
{
    $employees = [];
    foreach ($people as $number => $days) {
        $locked = $days['locked'] ?? false;
        unset($days['locked']);
        $employees[] = [
            'osobne_cislo' => (string) $number,
            'month_locked' => $locked,
            'days' => collect($days)->map(fn ($d, $date) => [
                'date' => $date,
                'planned_seconds' => $d[0] ? hm($d[0]) : 0,
                'plan_name' => $d[0] ? 'Plan '.$d[0] : '0H',
                'total_seconds' => $d[1] ? hm($d[1]) : 0,
                'absences' => $d[2] ?? [],
                'status' => 1,
            ])->values()->all(),
        ];
    }

    return ['month' => $month, 'generated_at' => now()->toIso8601String(), 'employees' => $employees];
}

function syncAttendance(string $month, array $people): array
{
    return app(AttendanceSync::class)->ingest(attendancePayload($month, $people));
}

function mandatoryGroup(int $perDay = 2, string $from = '2026-10-01', string $name = 'Kuchyňa'): MealGroup
{
    $group = MealGroup::query()->create(['name' => $name, 'default_tickets' => 0, 'mandatory_per_day' => $perDay, 'is_active' => true]);
    MealGroupMode::query()->create(['meal_group_id' => $group->id, 'mode' => PricingMode::Mandatory, 'effective_from' => $from]);

    return $group;
}
