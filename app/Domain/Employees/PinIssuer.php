<?php

namespace App\Domain\Employees;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Generates new PINs (admin "PIN reset" / access slips). The plain PIN is
 * returned once — for printing on the slip — and never stored.
 */
class PinIssuer
{
    public const LENGTH = 6;

    /**
     * @param  iterable<Employee>  $employees
     * @return Collection<int, array{employee_id: int, name: string, osobne_cislo: string, department: ?string, pin: string}>
     */
    public function issue(iterable $employees, User $by): Collection
    {
        return collect($employees)->map(function (Employee $employee) use ($by) {
            $pin = self::generate();
            $employee->setPin($pin);
            $employee->setRememberToken(Str::random(60)); // logs out all devices
            $employee->save();

            activity('employees')->performedOn($employee)->causedBy($by)->event('pin_reset')->log('PIN resetovaný');

            return [
                'employee_id' => $employee->id,
                'name' => $employee->fullName(),
                'osobne_cislo' => $employee->osobne_cislo,
                'department' => $employee->department,
                'pin' => $pin,
            ];
        })->values();
    }

    /** Random PIN without trivial patterns (000000, 123456, …). */
    public static function generate(): string
    {
        do {
            $pin = str_pad((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0', STR_PAD_LEFT);
        } while (count(array_unique(str_split($pin))) < 3 || str_contains('0123456789', $pin) || str_contains('9876543210', $pin));

        return $pin;
    }
}
