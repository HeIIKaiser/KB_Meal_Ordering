<?php

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Command;

/**
 * Emergency PIN reset from the command line (normally done in the admin panel).
 *   php artisan app:reset-pin 1234
 */
class ResetEmployeePin extends Command
{
    protected $signature = 'app:reset-pin {osobne_cislo} {--pin= : set this PIN instead of a random one}';

    protected $description = "Reset an employee's PIN and print the new one";

    public function handle(): int
    {
        $employee = Employee::query()->where('osobne_cislo', $this->argument('osobne_cislo'))->first();

        if (! $employee) {
            $this->error('Employee not found.');

            return self::FAILURE;
        }

        $pin = $this->option('pin') ?? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        if (! preg_match('/^\d{4,6}$/', $pin)) {
            $this->error('PIN must have 4–6 digits.');

            return self::FAILURE;
        }

        $employee->setPin($pin);
        $employee->setRememberToken(null); // log out all devices
        $employee->save();

        $this->info("{$employee->fullNameReversed()} ({$employee->osobne_cislo}) – new PIN: $pin");

        return self::SUCCESS;
    }
}
