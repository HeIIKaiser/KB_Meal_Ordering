<?php

use App\Models\Employee;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->withoutVite();
    $this->emp = employee(['osobne_cislo' => '1234']);
    $this->emp->pin_hash = Hash::make('4321');
    $this->emp->save();
});

it('redirects guests to the login page', function () {
    $this->get('/')->assertRedirect(route('login'));
    $this->get('/moj-mesiac')->assertRedirect(route('login'));
});

it('logs in with osobné číslo + PIN and always remembers the device for 400 days', function () {
    $response = $this->post('/prihlasenie', ['osobne_cislo' => ' 1234 ', 'pin' => '4321']);

    $response->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($this->emp, 'employee');

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_employee_'));
    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getExpiresTime() - time())->toBeGreaterThan(399 * 86400);
    expect($this->emp->fresh()->last_login_at)->not->toBeNull();
});

it('refuses a wrong PIN with a Slovak message', function () {
    $this->from('/prihlasenie')->post('/prihlasenie', ['osobne_cislo' => '1234', 'pin' => '0000'])
        ->assertRedirect('/prihlasenie')
        ->assertSessionHasErrors(['osobne_cislo' => 'Nesprávne osobné číslo alebo PIN.']);
    $this->assertGuest('employee');
});

it('refuses inactive employees', function () {
    $this->emp->update(['is_active' => false]);

    $this->post('/prihlasenie', ['osobne_cislo' => '1234', 'pin' => '4321'])->assertSessionHasErrors('osobne_cislo');
    $this->assertGuest('employee');
});

it('rate-limits failed attempts per osobné číslo + IP', function () {
    foreach (range(1, 5) as $i) {
        $this->post('/prihlasenie', ['osobne_cislo' => '1234', 'pin' => '0000']);
    }

    $this->post('/prihlasenie', ['osobne_cislo' => '1234', 'pin' => '4321'])->assertSessionHasErrors('osobne_cislo');
    expect(session('errors')->first('osobne_cislo'))->toStartWith('Príliš veľa neúspešných pokusov.');
    $this->assertGuest('employee');

    // another employee from the same IP is not blocked
    $other = employee(['osobne_cislo' => '999']);
    $this->post('/prihlasenie', ['osobne_cislo' => '999', 'pin' => '1234'])->assertRedirect(route('home'));
});

it('logs out a deactivated employee on the next request', function () {
    $this->actingAs($this->emp, 'employee');
    $this->emp->update(['is_active' => false]);

    $this->get('/')->assertRedirect(route('login'));
    $this->assertGuest('employee');
});

it('does not give employees access to the staff panel', function () {
    $this->actingAs($this->emp, 'employee')->get('/admin')->assertRedirect('/admin/login');
});

it('logs out', function () {
    $this->actingAs($this->emp, 'employee')->post('/odhlasenie')->assertRedirect(route('login'));
    $this->assertGuest('employee');
});

describe('PIN change', function () {
    beforeEach(fn () => $this->actingAs($this->emp, 'employee'));

    it('changes the PIN', function () {
        $this->put('/ucet/pin', ['current_pin' => '4321', 'pin' => '987654', 'pin_confirmation' => '987654'])
            ->assertRedirect(route('account'))
            ->assertSessionHas('status', 'PIN bol zmenený.');

        expect(Employee::find($this->emp->id)->checkPin('987654'))->toBeTrue();
        $this->assertAuthenticatedAs($this->emp, 'employee');
    });

    it('validates the PIN change', function (array $input, string $field, string $message) {
        $this->put('/ucet/pin', $input)->assertSessionHasErrors([$field => $message]);
        expect(Employee::find($this->emp->id)->checkPin('4321'))->toBeTrue();
    })->with([
        'wrong current' => [['current_pin' => '1111', 'pin' => '5555', 'pin_confirmation' => '5555'], 'current_pin', 'Súčasný PIN nie je správny.'],
        'too short' => [['current_pin' => '4321', 'pin' => '123', 'pin_confirmation' => '123'], 'pin', 'PIN musí mať 4 až 6 číslic.'],
        'not digits' => [['current_pin' => '4321', 'pin' => 'abcd', 'pin_confirmation' => 'abcd'], 'pin', 'PIN musí mať 4 až 6 číslic.'],
        'mismatch' => [['current_pin' => '4321', 'pin' => '5555', 'pin_confirmation' => '5556'], 'pin', 'Nový PIN a jeho zopakovanie sa nezhodujú.'],
    ]);
});
