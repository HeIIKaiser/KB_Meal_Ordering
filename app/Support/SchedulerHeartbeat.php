<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * The scheduler writes a timestamp every minute; health checks read it to prove
 * that the hosting cron really runs `php artisan schedule:run`.
 */
final class SchedulerHeartbeat
{
    private const FILE = 'scheduler-heartbeat.txt';

    public static function beat(): void
    {
        Storage::disk('local')->put(self::FILE, CarbonImmutable::now('UTC')->toIso8601String().' '.PHP_BINARY.' '.PHP_VERSION);
    }

    public static function last(): ?CarbonImmutable
    {
        $content = Storage::disk('local')->get(self::FILE);

        return $content ? CarbonImmutable::parse(strtok($content, ' ')) : null;
    }

    public static function details(): ?string
    {
        return Storage::disk('local')->get(self::FILE);
    }
}
