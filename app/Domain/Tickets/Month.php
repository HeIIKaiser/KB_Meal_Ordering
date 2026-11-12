<?php

namespace App\Domain\Tickets;

use App\Support\LocalTime;
use App\Support\SlovakDate;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/** Month keys ("YYYY-MM") used by ticket counts, overrides and closed months. */
final class Month
{
    public static function of(DateTimeInterface|string $date): string
    {
        return LocalTime::date($date)->format('Y-m');
    }

    public static function valid(string $month): bool
    {
        return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month);
    }

    public static function first(string $month): CarbonImmutable
    {
        if (! self::valid($month)) {
            throw new InvalidArgumentException("Invalid month $month");
        }

        return LocalTime::date($month.'-01');
    }

    public static function last(string $month): CarbonImmutable
    {
        return self::first($month)->endOfMonth()->startOfDay();
    }

    /** "november 2026" */
    public static function label(string $month): string
    {
        return SlovakDate::monthYear(self::first($month));
    }

    /** "november" */
    public static function name(string $month): string
    {
        return SlovakDate::MONTHS[(int) substr($month, 5, 2)];
    }

    public static function previous(string $month): string
    {
        return self::first($month)->subMonthNoOverflow()->format('Y-m');
    }

    public static function next(string $month): string
    {
        return self::first($month)->addMonthNoOverflow()->format('Y-m');
    }

    public static function current(): string
    {
        return LocalTime::today()->format('Y-m');
    }
}
