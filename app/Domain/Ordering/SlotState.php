<?php

namespace App\Domain\Ordering;

/**
 * Why a meal slot on a given date is (not) orderable. Evaluated at request time.
 */
enum SlotState: string
{
    case Open = 'open';
    case NoMenu = 'no_menu';
    case Closed = 'closed';          // closed day (holiday, …)
    case Locked = 'locked';          // manual lock by kitchen/admin
    case PastCutoff = 'past_cutoff'; // automatic cutoff passed
    case BeyondHorizon = 'beyond_horizon';
    case SlotInactive = 'slot_inactive';

    public function isOrderable(): bool
    {
        return $this === self::Open;
    }

    /** Short Slovak label for the employee UI. */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'otvorené',
            self::NoMenu => 'bez menu',
            self::Closed => 'uzavreté',
            self::Locked, self::PastCutoff => 'uzamknuté',
            self::BeyondHorizon => 'zatiaľ nie je možné objednať',
            self::SlotInactive => 'nedostupné',
        };
    }
}
