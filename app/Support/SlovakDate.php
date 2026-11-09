<?php

namespace App\Support;

use DateTimeInterface;

/** Slovak date labels without relying on the server's intl/locale data. */
final class SlovakDate
{
    public const DAYS_SHORT = [1 => 'Po', 2 => 'Ut', 3 => 'St', 4 => 'Št', 5 => 'Pi', 6 => 'So', 7 => 'Ne'];

    public const DAYS = [1 => 'pondelok', 2 => 'utorok', 3 => 'streda', 4 => 'štvrtok', 5 => 'piatok', 6 => 'sobota', 7 => 'nedeľa'];

    public const MONTHS = [1 => 'január', 2 => 'február', 3 => 'marec', 4 => 'apríl', 5 => 'máj', 6 => 'jún', 7 => 'júl', 8 => 'august', 9 => 'september', 10 => 'október', 11 => 'november', 12 => 'december'];

    public static function dayShort(DateTimeInterface $d): string
    {
        return self::DAYS_SHORT[(int) $d->format('N')];
    }

    public static function day(DateTimeInterface $d): string
    {
        return self::DAYS[(int) $d->format('N')];
    }

    /** "10. 11." */
    public static function dm(DateTimeInterface $d): string
    {
        return $d->format('j').'. '.$d->format('n').'.';
    }

    /** "utorok 10. 11. 2026" */
    public static function long(DateTimeInterface $d): string
    {
        return self::day($d).' '.self::dm($d).' '.$d->format('Y');
    }

    /** "november 2026" */
    public static function monthYear(DateTimeInterface $d): string
    {
        return self::MONTHS[(int) $d->format('n')].' '.$d->format('Y');
    }
}
