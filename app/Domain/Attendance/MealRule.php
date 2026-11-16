<?php

namespace App\Domain\Attendance;

use App\Support\Settings;

/**
 * Meals per day from worked (or planned) time — docs/ATTENDANCE.md §5:
 *   ≤ 4:00 → 0,  more than 4:00 and less than 10:00 → 1,  10:00 or more → 2
 * (both thresholds are settings), then the employee's max meals per day.
 */
class MealRule
{
    public function __construct(private Settings $settings) {}

    public function meals(int $seconds, ?int $cap = null): int
    {
        $one = $this->settings->int(Settings::ATTENDANCE_ONE_MEAL_SECONDS);
        $two = $this->settings->int(Settings::ATTENDANCE_TWO_MEALS_SECONDS);

        $meals = match (true) {
            $seconds >= $two => 2,
            $seconds > $one => 1,
            default => 0,
        };

        return $cap === null ? $meals : min($meals, $cap);
    }
}
