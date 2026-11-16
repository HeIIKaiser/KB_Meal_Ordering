<?php

namespace App\Filament\Widgets;

use App\Domain\Attendance\AttendanceSync;
use App\Filament\Pages\AttendanceStatus;
use App\Models\AttendanceSync as SyncLog;
use App\Support\LocalTime;
use App\Support\Settings;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Dashboard: last successful attendance sync; red after 36 h (docs/ATTENDANCE.md §4.4). Only once a token exists. */
class AttendanceSyncStatus extends StatsOverviewWidget
{
    protected static ?int $sort = -5;

    public static function canView(): bool
    {
        return AttendanceStatus::canAccess() && (bool) app(Settings::class)->get(Settings::ATTENDANCE_TOKEN_HASH);
    }

    protected function getStats(): array
    {
        $last = app(AttendanceSync::class)->lastSuccess();
        $error = SyncLog::query()->where('ok', false)->latest('received_at')->first();
        $stale = ! $last || $last->received_at->lt(now()->subHours(36));
        $failing = $error && (! $last || $error->received_at->gt($last->received_at));

        return [
            Stat::make('Dochádzka – posledná synchronizácia', $last ? LocalTime::toLocal($last->received_at)->format('j. n. H:i') : 'ešte nebola')
                ->description(match (true) {
                    $failing => 'Chyba mosta: '.mb_strimwidth((string) $error->message, 0, 80, '…'),
                    $stale => 'Viac ako 36 h bez synchronizácie – skontrolujte most na DOCKER01',
                    default => 'OK',
                })
                ->color($stale || $failing ? 'danger' : 'success')
                ->url(AttendanceStatus::getUrl()),
        ];
    }
}
