<?php

namespace App\Domain\Employees;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * One-off helper (Roman, 2026-11-16): payroll today imports into Vema with osobné číslo
 * written as a plain number (003 → 3). Fills vema_cislo that way for employees who have
 * none; never overwrites, skips non-numeric numbers and values already used.
 */
class VemaNumbers
{
    public function missingCount(): int
    {
        return Employee::query()->whereNull('vema_cislo')->count();
    }

    /** @return array{filled: int, skipped: list<string>} */
    public function fillFromOsobneCislo(): array
    {
        $filled = 0;
        $skipped = [];

        DB::transaction(function () use (&$filled, &$skipped) {
            $used = Employee::query()->whereNotNull('vema_cislo')->pluck('vema_cislo')->flip();
            foreach (Employee::query()->whereNull('vema_cislo')->orderBy('osobne_cislo')->get() as $e) {
                $number = trim($e->osobne_cislo);
                $vema = Employee::attendanceNumber($number);
                if (! ctype_digit($number) || isset($used[$vema])) {
                    $skipped[] = $e->osobne_cislo;

                    continue;
                }
                $e->update(['vema_cislo' => $vema]);
                $used[$vema] = true;
                $filled++;
            }
        });

        return ['filled' => $filled, 'skipped' => $skipped];
    }

    /** Export value: a number without leading zeros. */
    public static function export(?string $vema): ?int
    {
        return $vema === null || trim($vema) === '' ? null : (int) ltrim(trim($vema), '0');
    }
}
