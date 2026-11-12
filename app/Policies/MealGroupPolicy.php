<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\MealGroup;
use App\Models\User;

/** Employee groups (skupiny stravníkov): admin manages, payroll may read. Never deleted once used. */
class MealGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::Admin, Role::Payroll);
    }

    public function view(User $user, MealGroup $group): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function update(User $user, MealGroup $group): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function delete(User $user, MealGroup $group): bool
    {
        return $user->hasRole(Role::Admin) && ! $group->memberships()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
