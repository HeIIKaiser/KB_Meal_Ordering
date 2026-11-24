<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;

/**
 * Numbers stored as text (osobné číslo, Vema číslo, číslo v dochádzke, číslo strediska) in
 * numeric order: 1, 2, 10, 11, 100 instead of 1, 10, 100, 11, 2. Digits-only values come
 * first (by length without leading zeros, then by value, so "0123" sorts as 123); anything
 * else ("S001") after them, alphabetically; empty values last. No CAST, so long numbers
 * cannot overflow. Same rule in SQL (orderByNatural) and PHP (compare / sortByNatural).
 */
class NaturalSort
{
    /** Registers Builder::orderByNatural($column, $direction) and Collection::sortByNatural($key, $descending). */
    public static function register(): void
    {
        Builder::macro('orderByNatural', function (string $column, string $direction = 'asc') {
            /** @var Builder $this */
            foreach (NaturalSort::orderExpressions($this, $column, $direction) as [$expression, $dir]) {
                $this->orderBy($expression, $dir);
            }

            return $this;
        });

        Collection::macro('sortByNatural', function (callable|string $key, bool $descending = false) {
            /** @var Collection $this */
            $value = is_callable($key) ? $key : fn ($item) => data_get($item, $key);

            return $this->sort(function ($a, $b) use ($value, $descending) {
                $c = NaturalSort::compare($value($a), $value($b));

                return $descending ? -$c : $c;
            });
        });
    }

    /** @return list<array{0: Expression|string, 1: string}> */
    public static function orderExpressions(Builder $query, string $column, string $direction = 'asc'): array
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $col = $query->getGrammar()->wrap($column);
        [$digits, $stripped] = match ($query->getConnection()->getDriverName()) {
            'sqlite' => ["($col <> '' AND $col NOT GLOB '*[^0-9]*')", "LTRIM($col, '0')"],
            'pgsql' => ["($col ~ '^[0-9]+$')", "LTRIM($col, '0')"],
            default => ["($col REGEXP '^[0-9]+$')", "TRIM(LEADING '0' FROM $col)"], // MySQL / MariaDB
        };

        return [
            [new Expression("CASE WHEN $col IS NULL OR $col = '' THEN 1 ELSE 0 END"), 'asc'], // empty always last
            [new Expression("CASE WHEN $digits THEN 0 ELSE 1 END"), $direction],
            [new Expression("CASE WHEN $digits THEN LENGTH($stripped) ELSE 0 END"), $direction],
            [new Expression("CASE WHEN $digits THEN $stripped ELSE $col END"), $direction],
            [$column, $direction],
        ];
    }

    /** <0, 0, >0 like strcmp, in the order described above. */
    public static function compare(mixed $a, mixed $b): int
    {
        $ka = self::key($a);
        $kb = self::key($b);
        foreach ([0, 1] as $i) {
            if ($ka[$i] !== $kb[$i]) {
                return $ka[$i] <=> $kb[$i];
            }
        }

        return strcmp($ka[2], $kb[2]) ?: strcmp($ka[3], $kb[3]);
    }

    /** @return array{0: int, 1: int, 2: string, 3: string} group (0 digits, 1 other, 2 empty), length, value, original */
    private static function key(mixed $value): array
    {
        $v = trim((string) $value);
        if ($v === '') {
            return [2, 0, '', ''];
        }
        if (ctype_digit($v)) {
            $s = ltrim($v, '0');

            return [0, strlen($s), $s, $v];
        }

        return [1, 0, mb_strtolower($v), $v];
    }
}
