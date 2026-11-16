<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceType extends Model
{
    public const DONATED = 'donated';

    public const EXTRA = 'extra';

    /** Fixed-price mode (self-employed). Priced from fixed_prices, never by the daily rule. */
    public const FIXED = 'fixed';

    protected $fillable = ['code', 'name', 'sort', 'per_slot_daily_limit'];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'per_slot_daily_limit' => 'integer',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    public function isDonated(): bool
    {
        return $this->code === self::DONATED;
    }

    public function isExtra(): bool
    {
        return $this->code === self::EXTRA;
    }

    public function isFixed(): bool
    {
        return $this->code === self::FIXED;
    }

    /** The two types of the price list and the daily/ticket rules (without "fixed"). */
    public function scopeStandard($query): void
    {
        $query->whereIn('code', [self::DONATED, self::EXTRA]);
    }

    public static function byCode(string $code): self
    {
        return once(fn () => static::query()->get()->keyBy('code'))[$code]
            ?? static::query()->where('code', $code)->firstOrFail();
    }
}
