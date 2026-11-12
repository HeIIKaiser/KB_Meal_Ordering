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
    ) {}

    public function isTicketMode(): bool
    {
        return $this->ticketsMonth !== null;
    }

    public function isExtra(): bool
    {
        return ! $this->priceType->isDonated();
    }
}
