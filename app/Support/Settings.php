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

    /** Organisation name shown on access sheets, login page, installed app and 2FA; '' = no name. */
    public const COMPANY_NAME = 'company_name';

    /** Attendance (docs/ATTENDANCE.md §5): more than this many seconds of work = 1 meal … */
    public const ATTENDANCE_ONE_MEAL_SECONDS = 'attendance_one_meal_seconds';

    /** … this many seconds or more = 2 meals. */
    public const ATTENDANCE_TWO_MEALS_SECONDS = 'attendance_two_meals_seconds';

    /** SHA-256 of the bridge's bearer token (the token itself is shown once). */
    public const ATTENDANCE_TOKEN_HASH = 'attendance_token_hash';

    /** Optional comma-separated IP allowlist for the sync API. */
    public const ATTENDANCE_IP_ALLOWLIST = 'attendance_ip_allowlist';

    /** {"at": ISO time, "months": [...]} – "Synchronizovať teraz" pressed in the admin. */
    public const ATTENDANCE_SYNC_REQUEST = 'attendance_sync_request';

    /** Name of the installed app (home screen icon, header, login sheets); null = APP_NAME. */
    public const APP_NAME = 'app_name';

    public const DEFAULTS = [
        self::ORDERING_HORIZON_DAYS => 14,
        self::MAX_EXTRA_PER_SLOT => null, // null = unlimited (Roman 2026-11-09: extras are unlimited at 6 € each)
        self::COMPANY_NAME => null,
        self::ATTENDANCE_ONE_MEAL_SECONDS => 4 * 3600,
        self::ATTENDANCE_TWO_MEALS_SECONDS => 10 * 3600,
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

    /** From the admin settings; until it is saved there, APP_COMPANY from .env (per installation). */
    public function companyName(): ?string
    {
        $value = trim((string) ($this->get(self::COMPANY_NAME) ?? config('app.company')));

        return $value === '' ? null : $value;
    }

    /** Home-screen / header name of the employee app, e.g. "KBZ". */
    public function appName(): string
    {
        $value = trim((string) $this->get(self::APP_NAME));
        if ($value !== '') {
            return $value;
        }
        $env = trim((string) config('app.name'));

        return $env !== '' && $env !== 'Laravel' ? $env : 'Jedáleň';
    }

    /** "Jedáleň" or "Jedáleň <firma>" — the product name with the organisation, for titles. */
    public function appTitle(): string
    {
        return trim('Jedáleň '.$this->companyName());
    }

    /** Max. extra (full-price) orders per employee, slot and day; null = unlimited. */
    public function maxExtraPerSlot(): ?int
    {
        $value = $this->get(self::MAX_EXTRA_PER_SLOT);

        return $value === null || $value === '' ? null : max(0, (int) $value);
    }
}
