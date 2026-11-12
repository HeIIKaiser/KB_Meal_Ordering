<?php

namespace App\Domain\Ordering;

use App\Domain\Tickets\Month;
use App\Support\Money;

/**
 * Thrown when the next order would be an Extra (full price) one and the caller
 * did not explicitly accept that. The UI shows the message and asks to confirm.
 */
class ExtraConfirmationRequired extends OrderingException
{
    public function __construct(public readonly PriceQuote $quote)
    {
        parent::__construct(
            ($quote->isTicketMode() ? 'Lístky na '.Month::name($quote->ticketsMonth).' sú minuté. ' : '')
            .'Toto jedlo bude za '.Money::format($quote->unitPriceCents).'.',
            'extra_confirmation_required',
        );
    }
}
