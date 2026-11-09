<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceType extends Model
{
    public const DONATED = 'donated';

    public const EXTRA = 'extra';

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

    public static function byCode(string $code): self
    {
        return once(fn () => static::query()->get()->keyBy('code'))[$code]
            ?? static::query()->where('code', $code)->firstOrFail();
    }
}
