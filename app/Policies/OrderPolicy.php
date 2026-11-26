<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Order;
use App\Models\User;

/**
 * Orders: everyone on staff can read. Admin and kitchen may order on behalf of an
 * employee and cancel (Roman 2026-11-26); only admin corrects (switch item, price
 * type). Always via OrderService, which re-checks the role. Orders are never deleted.
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::Admin, Role::Kitchen, Role::Payroll);
    }

    public function view(User $user, Order $order): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Admin, Role::Kitchen);
    }

    public function cancel(User $user, Order $order): bool
    {
        return $user->hasRole(Role::Admin, Role::Kitchen);
    }

    public function update(User $user, Order $order): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function delete(User $user, Order $order): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
