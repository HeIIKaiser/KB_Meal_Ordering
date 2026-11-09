<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\User;

/** Employees: admin only. Never deleted — deactivated (order history stays). */
class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function delete(User $user, Employee $employee): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
