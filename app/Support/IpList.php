<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\IpUtils;

/** Comma/space-separated list of IPs or CIDR ranges. */
final class IpList
{
    public static function contains(string $list, string $ip): bool
    {
        $entries = array_values(array_filter(preg_split('/[\s,;]+/', $list) ?: []));

        return $entries !== [] && IpUtils::checkIp($ip, $entries);
    }

    /** @return list<string> invalid entries */
    public static function invalid(string $list): array
    {
        return array_values(array_filter(preg_split('/[\s,;]+/', $list) ?: [], function ($e) {
            [$ip, $bits] = array_pad(explode('/', $e, 2), 2, null);

            return $e !== '' && (filter_var($ip, FILTER_VALIDATE_IP) === false || ($bits !== null && ! ctype_digit($bits)));
        }));
    }
}
