<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Create or update a staff account (kitchen / payroll / admin) for /admin.
 *   php artisan app:staff-user roman@example.sk --role=admin
 * 2FA is set up by the user on first login.
 */
class CreateStaffUser extends Command
{
    protected $signature = 'app:staff-user {email} {--name=} {--role=* : admin, kitchen, payroll}';

    protected $description = 'Create or update a staff user for the admin panel';

    public function handle(): int
    {
        $email = mb_strtolower(trim($this->argument('email')));
        $user = User::query()->firstOrNew(['email' => $email]);

        $roles = $this->option('role') ?: multiselect('Roles', array_combine(
            array_column(Role::cases(), 'value'),
            array_map(fn (Role $r) => $r->label(), Role::cases()),
        ), required: true);

        $invalid = array_diff($roles, array_column(Role::cases(), 'value'));
        if ($invalid !== []) {
            $this->error('Unknown role(s): '.implode(', ', $invalid));

            return self::FAILURE;
        }

        $user->name = $this->option('name') ?: ($user->name ?? text('Name', required: true));
        $user->roles = array_values($roles);
        $user->is_active = true;

        if (! $user->exists || $this->confirm('Set a new password?', false)) {
            $user->password = password('Password (min. 12 characters)', required: true, validate: fn ($v) => mb_strlen($v) < 12 ? 'At least 12 characters.' : null);
        }

        $user->save();
        $this->info("Saved {$user->email} with roles: ".implode(', ', $user->roles));

        return self::SUCCESS;
    }
}
