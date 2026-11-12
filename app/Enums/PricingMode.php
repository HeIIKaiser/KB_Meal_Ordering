<?php

namespace App\Enums;

/** How meals of an employee are priced on a given day. */
enum PricingMode: string
{
    /** First lunch and first dinner of a day at the employee price, the rest at full price. */
    case Daily = 'daily';

    /** A monthly number of tickets at the employee price, no daily limits, the rest at full price. */
    case Tickets = 'tickets';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Denný (1 obed + 1 večera denne)',
            self::Tickets => 'Lístky (mesačný počet)',
        };
    }

    /** Value used in exports. */
    public function exportCode(): string
    {
        return match ($this) {
            self::Daily => 'denny',
            self::Tickets => 'listky',
        };
    }
}
