<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Typed access to the key/value `settings` table with defaults.
 * Registered as a scoped singleton (one instance per request/job).
 */
class Settings
{
    public const ORDERING_HORIZON_DAYS = 'ordering_horizon_days';

    public const MAX_EXTRA_PER_SLOT = 'max_extra_per_slot';

    public const DEFAULTS = [
        self::ORDERING_HORIZON_DAYS => 14,
        self::MAX_EXTRA_PER_SLOT => 1,   // OPEN (brief §13.3): confirm with Roman
    ];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $this->cache ??= Setting::query()->pluck('value', 'key')->all();

        return $this->cache[$key] ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        $this->cache = null;
    }

    public function orderingHorizonDays(): int
    {
        return max(0, $this->int(self::ORDERING_HORIZON_DAYS));
    }

    public function maxExtraPerSlot(): int
    {
        return max(0, $this->int(self::MAX_EXTRA_PER_SLOT));
    }
}
