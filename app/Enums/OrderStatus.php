<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktívna',
            self::Cancelled => 'Zrušená',
        };
    }
}
