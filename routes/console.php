<?php

use App\Support\SchedulerHeartbeat;
use Illuminate\Support\Facades\Schedule;

/*
| Hosting cron (every minute):  php /path/to/app/artisan schedule:run
| See README → "Cron".
*/

// Proves the cron works (checked by `php artisan app:hosting-check`).
Schedule::call(fn () => SchedulerHeartbeat::beat())->name('scheduler-heartbeat')->everyMinute();
