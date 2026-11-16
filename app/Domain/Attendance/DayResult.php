<?php

namespace App\Domain\Attendance;

/** Meals counted for one day and why (for previews and flags). */
final readonly class DayResult
{
    public const PLAN = 'plan';            // future day, or unfilled planned day of an open month

    public const ACTUAL = 'actual';        // worked time / absence filled in

    public const UNFILLED = 'unfilled';    // final: planned day with nothing filled in → 0, flagged

    public const NONE = 'none';            // day off / no data

    public function __construct(
        public string $date,
        public int $meals,
        public string $basis,
    ) {}
}
