<?php

use App\Domain\Employees\PinIssuer;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The demo seeder used to create well-known logins (staff …@jedalen.test / "heslo1234",
 * employees with PIN 1234). The repository is public now, so installations that ever ran
 * it get those logins disabled:
 *  - the three demo staff accounts are deactivated with a random password (not deleted:
 *    the audit log and slot locks may reference them);
 *  - every app-generated PIN "1234" gets a new random PIN (printable from Zamestnanci,
 *    devices logged out). PinIssuer generates 6-digit PINs, so only demo PINs match.
 */
return new class extends Migration
{
    public function up(): void
    {
        User::query()
            ->whereIn('email', ['admin@jedalen.test', 'kuchyna@jedalen.test', 'mzdy@jedalen.test'])
            ->each(function (User $user) {
                $user->forceFill(['is_active' => false, 'password' => Hash::make(Str::random(40))])->save();
            });

        Employee::query()
            ->where('pin_set_by', Employee::PIN_BY_SYSTEM)
            ->whereNotNull('pin_plain')
            ->lazyById()
            ->each(function (Employee $employee) {
                if ($employee->printablePin() !== '1234') {
                    return;
                }
                $employee->setPin(PinIssuer::generate(), Employee::PIN_BY_SYSTEM);
                $employee->setRememberToken(Str::random(60));
                $employee->slip_printed_at = null;
                $employee->saveQuietly();
            });
    }

    public function down(): void
    {
        // Intentionally irreversible.
    }
};
