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
     * Reset: give each employee a NEW PIN (logs them out on all devices).
     *
     * @param  iterable<Employee>  $employees
     * @return list<int> employee ids (for the slips page)
     */
    public function issue(iterable $employees, User $by): array
    {
        return collect($employees)->map(function (Employee $employee) use ($by) {
            $employee->setPin(self::generate(), Employee::PIN_BY_ADMIN);
            $employee->setRememberToken(Str::random(60)); // logs out all devices
            $employee->save();

            activity('employees')->performedOn($employee)->causedBy($by)->event('pin_reset')->log('PIN resetovaný');

            return $employee->id;
        })->values()->all();
    }

    /**
     * Data for access slips with the CURRENT PIN (nothing changes). Employees without
     * any PIN get one generated; for a PIN chosen by the employee (or otherwise not
     * printable) the slip carries a note instead. Marks the slips as printed and audits.
     *
     * @param  iterable<Employee>  $employees
     * @return Collection<int, array{employee_id: int, name: string, osobne_cislo: string, department: ?string, pin: ?string, note: ?string}>
     */
    public function slips(iterable $employees, User $by): Collection
    {
        $slips = collect($employees)->map(function (Employee $employee) {
            if (! $employee->hasPin()) {
                $employee->setPin(self::generate(), Employee::PIN_BY_SYSTEM);
            }
            $employee->slip_printed_at = now();
            $employee->saveQuietly();

            $pin = $employee->printablePin();

            return [
                'employee_id' => $employee->id,
                'name' => $employee->fullName(),
                'osobne_cislo' => $employee->osobne_cislo,
                'department' => $employee->department,
                'pin' => $pin,
                'note' => $pin !== null ? null : ($employee->pin_set_by === Employee::PIN_BY_EMPLOYEE
                    ? 'PIN si zmenil(a) sám/sama. Ak ho zabudol(a), administrátor vydá nový (Reset PIN).'
                    : 'PIN nie je známy. Administrátor vydá nový (Reset PIN).'),
            ];
        })->values();

        activity('employees')->causedBy($by)->event('slips_printed')
            ->withProperties(['employee_ids' => $slips->pluck('employee_id')->all()])
            ->log('Vytlačené prístupové lístky: '.$slips->count());

        return $slips;
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
