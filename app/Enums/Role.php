<?php

namespace App\Enums;

enum Role: string
{
    case Kitchen = 'kitchen';
    case Payroll = 'payroll';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Kitchen => 'Kuchyňa',
            self::Payroll => 'Mzdová účtovníčka',
            self::Admin => 'Administrátor',
        };
    }
}
