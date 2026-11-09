<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PinController extends Controller
{
    public function edit(): View
    {
        return view('employee.pin');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_pin' => ['required', 'string'],
            'pin' => ['required', 'regex:/^\d{4,6}$/', 'confirmed'],
        ], [
            'current_pin.required' => 'Zadajte súčasný PIN.',
            'pin.required' => 'Zadajte nový PIN.',
            'pin.regex' => 'PIN musí mať 4 až 6 číslic.',
            'pin.confirmed' => 'Nový PIN a jeho zopakovanie sa nezhodujú.',
        ]);

        /** @var Employee $employee */
        $employee = Auth::guard('employee')->user();

        if (! $employee->checkPin($data['current_pin'])) {
            throw ValidationException::withMessages(['current_pin' => 'Súčasný PIN nie je správny.']);
        }

        $employee->setPin($data['pin'], Employee::PIN_BY_EMPLOYEE); // not stored readable
        // New remember token logs out other devices; this device logs in again below.
        $employee->setRememberToken(Str::random(60));
        $employee->save();

        Auth::guard('employee')->login($employee, remember: true);
        $request->session()->regenerate();

        return redirect()->route('account')->with('status', 'PIN bol zmenený.');
    }
}
