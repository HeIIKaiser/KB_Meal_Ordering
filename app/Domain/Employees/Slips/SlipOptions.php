<?php

namespace App\Domain\Employees\Slips;

use App\Models\User;

/**
 * How access slips are printed: sort order, grouping into sections, a new page per
 * section (small slips) and the format. Built from query parameters with a whitelist;
 * anything unknown falls back to the default. The last choice is remembered per staff user.
 *
 *   ?zoradit=meno|cislo|stredisko|skupina &skupiny=nie|stredisko|skupina|forma &strana=1|0 &format=a4|male
 */
final class SlipOptions
{
    public const SORTS = [
        'meno' => 'Priezvisko a meno',
        'cislo' => 'Osobné číslo',
        'stredisko' => 'Stredisko (číslo, potom názov)',
        'skupina' => 'Skupina stravníkov',
    ];

    public const GROUPS = [
        'nie' => 'Nie',
        'stredisko' => 'Podľa strediska',
        'skupina' => 'Podľa skupiny stravníkov',
        'forma' => 'Podľa formy stravy',
    ];

    public const FORMATS = [
        'a4' => 'A4 s návodom (1 strana na zamestnanca)',
        'male' => 'Malé lístky (8 na A4)',
    ];

    public function __construct(
        public readonly string $sort = 'meno',
        public readonly string $group = 'nie',
        public readonly bool $newPage = true,
        public readonly string $format = 'a4',
    ) {}

    /** From query parameters or a form; invalid values → defaults. */
    public static function fromArray(array $input): self
    {
        $pick = fn (string $key, array $allowed, string $default) => is_string($input[$key] ?? null) && array_key_exists($input[$key], $allowed) ? $input[$key] : $default;
        $page = $input['strana'] ?? null;

        return new self(
            sort: $pick('zoradit', self::SORTS, 'meno'),
            group: $pick('skupiny', self::GROUPS, 'nie'),
            newPage: match (true) {
                in_array($page, [false, 0, '0'], true) => false,
                default => true, // also for junk
            },
            format: $pick('format', self::FORMATS, 'a4'),
        );
    }

    /** The user's last choice (defaults if none). */
    public static function forUser(?User $user): self
    {
        return self::fromArray((array) ($user?->preferences['slips'] ?? []));
    }

    public function remember(User $user): self
    {
        $user->forceFill(['preferences' => [...($user->preferences ?? []), 'slips' => $this->toQuery()]])->saveQuietly();

        return $this;
    }

    public function grouped(): bool
    {
        return $this->group !== 'nie';
    }

    public function small(): bool
    {
        return $this->format === 'male';
    }

    /** One person: sorting/grouping make no sense, keep only the format. */
    public function single(): self
    {
        return new self(format: $this->format);
    }

    public function with(array $changes): self
    {
        return self::fromArray([...$this->toQuery(), ...$changes]);
    }

    /** @return array{zoradit: string, skupiny: string, strana: int, format: string} */
    public function toQuery(): array
    {
        return ['zoradit' => $this->sort, 'skupiny' => $this->group, 'strana' => $this->newPage ? 1 : 0, 'format' => $this->format];
    }

    /** State for the Filament form (strana as bool). */
    public function toForm(): array
    {
        return [...$this->toQuery(), 'strana' => $this->newPage];
    }
}
