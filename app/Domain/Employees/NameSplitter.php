<?php

namespace App\Domain\Employees;

use Illuminate\Support\Str;

/**
 * Splits one "Name" cell of the master sheet (Roman, 2026-11-24): the LAST word is the
 * first name, everything before it the surname. Academic titles are dropped first
 * ("Ing. Novák Ján, PhD." → Novák / Ján; "Filip Hanzen Erika" → Filip Hanzen / Erika).
 */
class NameSplitter
{
    /** Lower-case, without dots; recognised with or without the dot. */
    private const TITLES = [
        'bc', 'bca', 'mgr', 'mga', 'ing', 'mudr', 'mddr', 'mvdr', 'phdr', 'paeddr', 'rndr', 'judr', 'thdr',
        'thlic', 'pharmdr', 'rsdr', 'icdr', 'phd', 'artd', 'csc', 'drsc', 'dis', 'mba', 'mph', 'llm', 'msc', 'bsc',
    ];

    /** Could also be a word of a name, so only with the dot ("doc.", "Ing. arch."). */
    private const TITLES_WITH_DOT = ['doc', 'prof', 'dr', 'arch', 'akad', 'mal', 'soch'];

    /** @return array{last_name: ?string, first_name: ?string, words: int} */
    public static function split(string $full): array
    {
        $words = self::words($full);
        if ($words === []) {
            return ['last_name' => null, 'first_name' => null, 'words' => 0];
        }
        if (count($words) === 1) {
            return ['last_name' => $words[0], 'first_name' => null, 'words' => 1];
        }
        $first = array_pop($words);

        return ['last_name' => implode(' ', $words), 'first_name' => $first, 'words' => count($words) + 1];
    }

    /** Words of the name without titles and commas. @return list<string> */
    public static function words(string $full): array
    {
        $tokens = preg_split('/[\s,]+/u', trim($full), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($tokens, fn (string $t) => ! self::isTitle($t)));
    }

    public static function isTitle(string $token): bool
    {
        $lower = Str::ascii(mb_strtolower($token));
        if (in_array(str_replace('.', '', $lower), self::TITLES, true)) {
            return true; // "Ing.", "PhD", "LL.M."
        }
        // "Ing.Mgr.", "doc.", "arch." – every dot-separated part is a title and the token has a dot
        $parts = array_filter(explode('.', $lower), fn ($p) => $p !== '');

        return str_contains($lower, '.') && collect($parts)->every(fn ($p) => in_array($p, [...self::TITLES, ...self::TITLES_WITH_DOT], true));
    }

    /**
     * Does "Kováčiková M." (name in the attendance system) fit this employee? Surname must
     * match (case/diacritics ignored), the last word's first letter must match the first name.
     */
    public static function matchesAttendanceName(string $attendanceName, string $lastName, string $firstName): bool
    {
        $words = self::words($attendanceName);
        if ($words === []) {
            return true;
        }
        $key = fn (string $s) => Str::ascii(mb_strtolower(trim($s)));
        if (count($words) === 1) {
            return $key($words[0]) === $key($lastName);
        }
        $initial = rtrim(array_pop($words), '.');

        return $key(implode(' ', $words)) === $key($lastName)
            && mb_substr($key($initial), 0, 1) === mb_substr($key($firstName), 0, 1);
    }
}
