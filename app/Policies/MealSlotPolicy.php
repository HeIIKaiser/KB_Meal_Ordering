<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\MealSlot;
use App\Models\User;

/** Meal slots (Obed, Večera, …): admin only; deactivate instead of delete. */
class MealSlotPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function view(User $user, MealSlot $slot): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function update(User $user, MealSlot $slot): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function delete(User $user, MealSlot $slot): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
