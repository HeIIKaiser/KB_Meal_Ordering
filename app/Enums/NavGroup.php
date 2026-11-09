<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Filament navigation groups, in display order. */
enum NavGroup: string implements HasLabel
{
    case Orders = 'Objednávky';
    case Menus = 'Jedálny lístok';
    case People = 'Ľudia';
    case Settings = 'Nastavenia';

    public function getLabel(): string
    {
        return $this->value;
    }
}
