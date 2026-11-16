<?php

namespace App\Http\Middleware;

use App\Support\IpList;
use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Bearer token of the attendance bridge (stored as SHA-256), HTTPS in production, optional IP allowlist. */
class AttendanceApiToken
{
    public function __construct(private Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isProduction() && ! $request->secure()) {
            return response()->json(['error' => 'HTTPS required.'], 403);
        }

        $allow = trim((string) $this->settings->get(Settings::ATTENDANCE_IP_ALLOWLIST));
        if ($allow !== '' && ! IpList::contains($allow, (string) $request->ip())) {
            return response()->json(['error' => 'IP address not allowed.'], 403);
        }

        $hash = (string) $this->settings->get(Settings::ATTENDANCE_TOKEN_HASH);
        $token = (string) $request->bearerToken();
        if ($hash === '' || $token === '' || ! hash_equals($hash, hash('sha256', $token))) {
            return response()->json(['error' => 'Invalid or missing token.'], 401);
        }

        return $next($request);
    }
}
