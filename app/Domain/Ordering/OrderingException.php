<?php

namespace App\Domain\Ordering;

use App\Models\MealSlot;
use RuntimeException;

/**
 * A business-rule refusal. The message is plain Slovak and safe to show to the user.
 */
class OrderingException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'refused')
    {
        parent::__construct($message);
    }

    public static function notOrderable(SlotAvailability $availability, MealSlot $slot): self
    {
        return new self($availability->refusalMessage($slot), $availability->state->value);
    }
}
