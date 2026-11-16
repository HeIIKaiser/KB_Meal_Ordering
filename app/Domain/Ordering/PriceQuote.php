<?php

namespace App\Domain\Ordering;

use App\Models\PriceType;

/** What the employee's next order in a slot/day would be typed and cost. */
final readonly class PriceQuote
{
    public function __construct(
        public PriceType $priceType,
        public int $unitPriceCents,
        public ?string $ticketsMonth = null,  // set in ticket mode ("YYYY-MM")
        public ?int $ticketsLeft = null,
        public bool $mandatory = false,      // mandatory mode (povinná strava)
    ) {}

    public function isTicketMode(): bool
    {
        return $this->ticketsMonth !== null;
    }

    /** Needs the "this costs 6 €" confirmation (Dokupovaná strava). Fixed prices never do. */
    public function isExtra(): bool
    {
        return $this->priceType->isExtra();
    }

    public function isFixed(): bool
    {
        return $this->priceType->isFixed();
    }
}
