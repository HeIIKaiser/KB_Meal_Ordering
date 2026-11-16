<?php

namespace App\Filament\Concerns;

use Illuminate\Support\Str;

/**
 * Slovak headings use sentence case: Filament's default title case turns "pevné ceny"
 * into "Pevné Ceny"; this capitalises only the first letter ("Pevné ceny").
 */
trait SentenceCaseLabels
{
    public static function getTitleCaseModelLabel(): string
    {
        return Str::ucfirst(static::getModelLabel());
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return Str::ucfirst(static::getPluralModelLabel());
    }
}
