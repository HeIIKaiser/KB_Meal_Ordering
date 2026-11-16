<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\FixedPrice;
use App\Models\User;
use App\Support\LocalTime;

/** Fixed prices: admin manages, payroll reads. A version already in effect can't be changed (add a new one). */
class FixedPricePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::Admin, Role::Payroll);
    }

    public function view(User $user, FixedPrice $price): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function update(User $user, FixedPrice $price): bool
    {
        return $user->hasRole(Role::Admin) && $price->valid_from->gt(LocalTime::today());
    }

    public function delete(User $user, FixedPrice $price): bool
    {
        return $this->update($user, $price);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
