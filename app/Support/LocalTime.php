<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The app stores timestamps in UTC (app.timezone) but all business dates and
 * cutoffs are evaluated in the local timezone (Europe/Bratislava).
 */
final class LocalTime
{
    public static function zone(): string
    {
        return config('jedalen.timezone', 'Europe/Bratislava');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now()->setTimezone(self::zone());
    }

    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    /** A calendar date (Y-m-d) as local midnight. */
    public static function date(DateTimeInterface|string $date): CarbonImmutable
    {
        $ymd = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : substr($date, 0, 10);

        return CarbonImmutable::createFromFormat('!Y-m-d', $ymd, self::zone());
    }

    /** Convert any instant to local time for display. */
    public static function toLocal(?DateTimeInterface $instant): ?CarbonImmutable
    {
        return $instant ? CarbonImmutable::instance($instant)->setTimezone(self::zone()) : null;
    }
}
