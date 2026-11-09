<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/** Staff accounts: admin only. Deactivate instead of deleting (audit references). */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function view(User $user, User $model): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function update(User $user, User $model): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
