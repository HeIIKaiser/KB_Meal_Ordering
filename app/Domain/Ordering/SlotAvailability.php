<?php

namespace App\Domain\Ordering;

use App\Models\ClosedDay;
use App\Models\MealSlot;
use Carbon\CarbonImmutable;

final readonly class SlotAvailability
{
    public function __construct(
        public SlotState $state,
        public CarbonImmutable $cutoffAt,   // in local timezone
        public ?ClosedDay $closedDay = null,
    ) {}

    public function isOrderable(): bool
    {
        return $this->state->isOrderable();
    }

    /** Slovak message for a refused order/cancellation. */
    public function refusalMessage(MealSlot $slot): string
    {
        $what = $slot->accusative();

        return match ($this->state) {
            SlotState::Open => '',
            SlotState::NoMenu => "Na tento deň ešte nie je zverejnené menu ($what).",
            SlotState::Closed => trim("V tento deň sa $what nevydáva. ".($this->closedDay?->note ?? '')),
            SlotState::Locked, SlotState::PastCutoff => 'Objednávky na '.$what.' sú už uzavreté.',
            SlotState::BeyondHorizon => 'Na tento deň sa zatiaľ nedá objednať.',
            SlotState::SlotInactive => 'Toto jedlo sa momentálne neobjednáva.',
        };
    }
}
