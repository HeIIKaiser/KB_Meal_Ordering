<?php

use App\Domain\Attendance\AttendanceSync;
use App\Domain\Backup\DatabaseBackup;
use App\Support\SchedulerHeartbeat;
use Illuminate\Support\Facades\Schedule;

/*
| Hosting cron (every minute) runs `artisan schedule:run` — on Websupport through
| deploy/websupport-cron.php. See README → Cron.
|
| Tasks run in-process (Schedule::call), so they don't need proc_open/exec.
*/

// Proves the cron works (checked by `php artisan app:hosting-check`).
Schedule::call(fn () => SchedulerHeartbeat::beat())->name('scheduler-heartbeat')->everyMinute();

// Nightly database backup with 30-day rotation (README → Backups).
Schedule::call(function (DatabaseBackup $backup) {
    $backup->create();
    $backup->prune(30);
})->name('database-backup')->dailyAt('02:30')->timezone('Europe/Bratislava')->withoutOverlapping(60);

// Attendance (docs/ATTENDANCE.md §5.1): the estimate depends on "today" (future = plan,
// past = actual), so refresh open months every night even if the bridge sent nothing.
Schedule::call(fn (AttendanceSync $sync) => $sync->recomputeOpenMonths())
    ->name('attendance-recompute')->dailyAt('03:30')->timezone('Europe/Bratislava')->withoutOverlapping(30);
