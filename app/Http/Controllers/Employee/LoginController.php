<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Employee login: osobné číslo + PIN. Remember-me is always on (400-day,
 * HttpOnly cookie), so on the same device users practically never log in again.
 */
class LoginController extends Controller
{
    public function create(): View
    {
        return view('employee.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'osobne_cislo' => ['required', 'string', 'max:32'],
            'pin' => ['required', 'string'],
        ], [
            'osobne_cislo.required' => 'Zadajte osobné číslo.',
            'pin.required' => 'Zadajte PIN.',
        ]);

        $number = trim($data['osobne_cislo']);
        $key = 'employee-login:'.Str::lower($number).'|'.$request->ip();
        $numberKey = 'employee-login-number:'.Str::lower($number);

        foreach ([[$key, 'login_max_attempts'], [$numberKey, 'login_max_attempts_per_number']] as [$k, $limit]) {
            if (RateLimiter::tooManyAttempts($k, config("jedalen.$limit"))) {
                $seconds = RateLimiter::availableIn($k);
                $wait = $seconds > 90 ? ceil($seconds / 60).' min' : "$seconds s";

                throw ValidationException::withMessages([
                    'osobne_cislo' => "Príliš veľa neúspešných pokusov. Skúste to znova o $wait.",
                ]);
            }
        }

        // App-generated PINs are hashed lazily on the first login (see Employee::setPin).
        Employee::query()->where('osobne_cislo', $number)->where('is_active', true)->first()?->ensurePinHash();

        $ok = Auth::guard('employee')->attempt([
            'osobne_cislo' => $number,
            'is_active' => true,
            'password' => $data['pin'],
        ], remember: true);

        if (! $ok) {
            RateLimiter::hit($key, config('jedalen.login_decay_seconds'));
            RateLimiter::hit($numberKey, config('jedalen.login_decay_seconds_per_number'));

            throw ValidationException::withMessages([
                'osobne_cislo' => 'Nesprávne osobné číslo alebo PIN.',
            ]);
        }

        RateLimiter::clear($key);
        RateLimiter::clear($numberKey);
        $request->session()->regenerate();

        /** @var Employee $employee */
        $employee = Auth::guard('employee')->user();
        $employee->forceFill(['last_login_at' => now()])->saveQuietly();

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('employee')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
