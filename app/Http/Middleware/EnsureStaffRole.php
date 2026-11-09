<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the plain (non-Filament) staff routes: print pages, PDFs, exports,
 * access slips, import templates.  Usage: ->middleware('staff:kitchen,admin')
 *
 * Guests are sent to the staff login; inactive users or users without one of
 * the roles get 403.
 */
class EnsureStaffRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = Auth::guard('web')->user();

        if (! $user instanceof User) {
            return redirect()->guest('/admin/login');
        }

        $allowed = array_map(fn (string $r) => Role::from($r), $roles);
        abort_unless($user->is_active && $user->hasRole(...$allowed), 403);

        return $next($request);
    }
}
