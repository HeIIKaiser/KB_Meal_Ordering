<?php

namespace App\Casts;

use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A calendar date (no time). Stored as "Y-m-d", read as local-midnight CarbonImmutable
 * in the business timezone, so comparisons with LocalTime::date()/today() are exact.
 */
class LocalDate implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : LocalTime::date((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : LocalTime::date((string) $value)->format('Y-m-d');
    }
}
