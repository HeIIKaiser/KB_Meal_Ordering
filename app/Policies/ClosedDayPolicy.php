<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\ClosedDay;
use App\Models\User;

/** Closed days: admin manages, kitchen may read. */
class ClosedDayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::Admin, Role::Kitchen);
    }

    public function view(User $user, ClosedDay $day): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function update(User $user, ClosedDay $day): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function delete(User $user, ClosedDay $day): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole(Role::Admin);
    }
}
