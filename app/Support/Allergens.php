<?php

namespace App\Support;

/** EU allergens 1–14, stored as a normalised "1,3,7" string. */
final class Allergens
{
    public const NAMES = [
        1 => 'Obilniny obsahujúce lepok',
        2 => 'Kôrovce',
        3 => 'Vajcia',
        4 => 'Ryby',
        5 => 'Arašidy',
        6 => 'Sója',
        7 => 'Mlieko',
        8 => 'Orechy',
        9 => 'Zeler',
        10 => 'Horčica',
        11 => 'Sezam',
        12 => 'Oxid siričitý a siričitany',
        13 => 'Vlčí bôb',
        14 => 'Mäkkýše',
    ];

    /** @return list<int> */
    public static function parse(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        preg_match_all('/\d+/', $value, $m);
        $numbers = array_values(array_unique(array_map('intval', $m[0])));
        sort($numbers);

        return array_values(array_filter($numbers, fn (int $n) => $n >= 1 && $n <= 14));
    }

    /** True when the string only contains valid allergen numbers and separators. */
    public static function isValid(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return true;
        }
        if (! preg_match('/^[\d\s,;.\/]+$/', $value)) {
            return false;
        }
        preg_match_all('/\d+/', $value, $m);

        return collect($m[0])->every(fn ($n) => (int) $n >= 1 && (int) $n <= 14);
    }

    public static function normalize(?string $value): ?string
    {
        $list = self::parse($value);

        return $list === [] ? null : implode(',', $list);
    }
}
