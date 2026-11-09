<?php

namespace App\Domain\Ordering;

use App\Enums\Role;
use App\Models\MealSlot;
use App\Models\SlotLock;
use App\Models\User;
use App\Support\LocalTime;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;

/** Manual lock/unlock of a slot on a date (kitchen or admin). Audited. */
class SlotLockService
{
    public function lock(DateTimeInterface|string $date, MealSlot $slot, User $user): SlotLock
    {
        $this->authorize($user);
        $ymd = LocalTime::date($date)->toDateString();

        $lock = SlotLock::query()->firstOrCreate(
            ['date' => $ymd, 'meal_slot_id' => $slot->id],
            ['locked_by_user_id' => $user->id, 'locked_at' => now()],
        );

        if ($lock->wasRecentlyCreated) {
            activity('locks')->performedOn($lock)->causedBy($user)->event('locked')
                ->withProperties(['date' => $ymd, 'meal_slot' => $slot->code])
                ->log('Uzamknuté: '.$slot->name.' '.$ymd);
        }

        return $lock;
    }

    public function unlock(DateTimeInterface|string $date, MealSlot $slot, User $user): void
    {
        $this->authorize($user);
        $ymd = LocalTime::date($date)->toDateString();

        $lock = SlotLock::query()->where('date', $ymd)->where('meal_slot_id', $slot->id)->first();

        if ($lock) {
            $lock->delete();
            activity('locks')->performedOn($lock)->causedBy($user)->event('unlocked')
                ->withProperties(['date' => $ymd, 'meal_slot' => $slot->code])
                ->log('Odomknuté: '.$slot->name.' '.$ymd);
        }
    }

    public function isLocked(DateTimeInterface|string $date, MealSlot $slot): bool
    {
        return SlotLock::query()->where('date', LocalTime::date($date)->toDateString())->where('meal_slot_id', $slot->id)->exists();
    }

    private function authorize(User $user): void
    {
        if (! $user->is_active || ! $user->hasRole(Role::Kitchen, Role::Admin)) {
            throw new AuthorizationException;
        }
    }
}
