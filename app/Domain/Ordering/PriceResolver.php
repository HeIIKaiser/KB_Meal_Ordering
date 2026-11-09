<?php

namespace App\Domain\Ordering;

use App\Models\Price;
use App\Models\PriceType;
use App\Support\LocalTime;
use DateTimeInterface;

/**
 * Prices are versioned by valid_from. The version that applies to an order is the
 * one with the latest valid_from on or before the MEAL date. The result is
 * snapshotted on the order, so adding a new version never changes existing orders.
 */
class PriceResolver
{
    public function current(PriceType $type, DateTimeInterface|string $date): ?Price
    {
        return Price::query()
            ->where('price_type_id', $type->id)
            ->where('valid_from', '<=', LocalTime::date($date)->toDateString())
            ->orderByDesc('valid_from')
            ->first();
    }

    public function amountFor(PriceType $type, DateTimeInterface|string $date): int
    {
        $price = $this->current($type, $date)
            ?? throw new OrderingException('Pre tento deň nie je nastavená cena ('.$type->name.'). Kontaktujte administrátora.', 'no_price');

        return $price->amount_cents;
    }
}
