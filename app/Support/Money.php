<?php

namespace App\Support;

final class Money
{
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
