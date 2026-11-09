<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Price;
use App\Models\User;
use App\Support\LocalTime;

/**
 * Prices: admin manages, payroll may read. A version that is already in effect
 * can't be edited or deleted — add a new version instead (orders keep their
 * snapshot either way, but history must stay readable).
 */
class PricePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::Admin, Role::Payroll);
    }

    public function view(User $user, Price $price): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function update(User $user, Price $price): bool
    {
        return $user->hasRole(Role::Admin) && $price->valid_from->gt(LocalTime::today());
    }

    public function delete(User $user, Price $price): bool
    {
        return $this->update($user, $price);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
