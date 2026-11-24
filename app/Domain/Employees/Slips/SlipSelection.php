<?php

namespace App\Domain\Employees\Slips;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Which employees a slips URL prints. Short lists stay in the URL (?zamestnanci=1,2,3 –
 * bookmarkable); longer ones (up to 1000) go into a short-lived token bound to the staff
 * user (?vyber=…), so the URL never gets too long for the server or the browser.
 */
final class SlipSelection
{
    public const MAX = 1000;

    public const INLINE_MAX = 200;

    public const TTL_MINUTES = 120;

    /** @param  iterable<int>  $ids @return array<string, string> query parameters */
    public static function params(iterable $ids, ?User $user): array
    {
        $ids = self::clean(collect($ids));
        if ($ids->count() <= self::INLINE_MAX || ! $user) {
            return ['zamestnanci' => $ids->implode(',')];
        }
        $token = Str::random(40);
        Cache::put(self::key($token), ['user' => $user->id, 'ids' => $ids->all()], now()->addMinutes(self::TTL_MINUTES));

        return ['vyber' => $token];
    }

    /** @return Collection<int, int> ids from ?zamestnanci or ?vyber (only the user's own, unexpired token) */
    public static function resolve(Request $request, ?User $user): Collection
    {
        $token = $request->query('vyber');
        if (is_string($token) && $token !== '') {
            $stored = preg_match('/^[A-Za-z0-9]{40}$/', $token) ? Cache::get(self::key($token)) : null;

            return $stored && $user && (int) $stored['user'] === $user->id ? self::clean(collect($stored['ids'])) : collect();
        }

        return self::clean(collect(explode(',', (string) $request->query('zamestnanci'))));
    }

    private static function clean(Collection $ids): Collection
    {
        return $ids->map(fn ($id) => (int) trim((string) $id))->filter(fn ($id) => $id > 0)->unique()->take(self::MAX)->values();
    }

    private static function key(string $token): string
    {
        return 'slips-selection:'.$token;
    }
}
