<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every web response.
 *
 * CSP notes: Livewire/Alpine evaluate expressions at runtime ('unsafe-eval') and
 * Livewire/Filament inject inline scripts/styles ('unsafe-inline'). Everything else is
 * locked to our own origin: no third-party scripts, fonts, frames or form targets.
 */
class SecurityHeaders
{
    public const CSP = "default-src 'self'; "
        ."script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
        ."style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: blob:; "
        ."font-src 'self' data:; "
        ."connect-src 'self'; "
        ."worker-src 'self'; "
        ."manifest-src 'self'; "
        ."frame-src 'self' blob:; "
        ."frame-ancestors 'none'; "
        ."form-action 'self'; "
        ."base-uri 'self'; "
        ."object-src 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        header_remove('X-Powered-By');

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', self::CSP);
        }

        // HTTPS only in production: browsers will refuse plain HTTP for a year.
        if (app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
