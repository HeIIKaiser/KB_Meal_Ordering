<?php

namespace App\Support;

final class Money
{
    /**
     * Shown on every monthly rundown (employee "Môj mesiac", monthly export): the app
     * gives every first lunch and first dinner the donated price, but 8-hour shifts
     * are entitled to only one donated meal a day — payroll settles it against
     * attendance. (Roman, 2026-11-09)
     */
    public const ESTIMATE_DISCLAIMER = 'Cena stravy je odhadom a po porovnaní s dochádzkou sa môže zmeniť.';

    /** Instead of the disclaimer once the month is closed (final ticket counts imported). */
    public const CLOSED_NOTE = 'Mesiac uzavretý – konečné ceny.';

    /** Added for ticket mode while the month is open. */
    public const TICKETS_NOTE = 'Počet lístkov sa na konci mesiaca upraví podľa dochádzky.';

    /** 150 → "1,50 €" */
    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.number_format(intdiv($cents, 100) + ($cents % 100) / 100, 2, ',', ' ').' €';
    }

    /** "1,50" / "1.50" / "1,50 €" → 150. Returns null if not parseable. */
    public static function parse(string $value): ?int
    {
        $value = trim(str_replace(['€', ' ', "\u{00A0}"], '', $value));
        $value = str_replace(',', '.', $value);

        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $value)) {
            return null;
        }

        return (int) round(((float) $value) * 100);
    }
}
