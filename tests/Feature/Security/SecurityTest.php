<?php

use App\Http\Middleware\SecurityHeaders;
use App\Livewire\OrderScreen;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(fn () => $this->withoutVite());

describe('routes', function () {
    /** Routes that may be reached without logging in. Anything else must require auth. */
    $public = [
        'up', 'prihlasenie', 'admin/login',
        'livewire*', 'filament/assets*', // framework assets / endpoints (Livewire re-applies the page's auth)
    ];

    it('requires authentication on every non-public route', function () use ($public) {
        $unprotected = collect(Route::getRoutes()->getRoutes())
            ->reject(fn ($r) => collect($public)->contains(fn ($p) => Str::is($p, $r->uri())))
            ->reject(function ($r) {
                $mw = collect($r->gatherMiddleware())->map(fn ($m) => is_string($m) ? $m : '');

                return $mw->contains(fn ($m) => str_starts_with($m, 'auth:')
                    || str_starts_with($m, 'staff:')
                    || str_contains($m, 'Filament\\Http\\Middleware\\Authenticate')
                    || $m === 'filament.actions');
            })
            ->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())
            ->values()
            ->all();

        expect($unprotected)->toBe([]);
    });

    it('has no web route into private storage', function () {
        expect(collect(Route::getRoutes()->getRoutes())->map->uri()->filter(fn ($u) => str_starts_with($u, 'storage')))->toBeEmpty();
    });

    it('sends guests of staff helper routes to the staff login and refuses non-staff', function (string $url) {
        $this->get($url)->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs(User::factory()->admin()->create(['is_active' => false]))->get($url)->assertForbidden();
    })->with([
        '/admin/listky', '/admin/sablona/menu', '/admin/kuchyna/tlac?datum=2026-11-10&jedlo=obed',
        '/admin/kuchyna/pdf?datum=2026-11-10&jedlo=obed', '/admin/exporty/mesiac?mesiac=2026-11',
    ]);

    it('does not let an employee login open the staff panel', function () {
        $this->actingAs(employee(), 'employee')->get('/admin')->assertRedirect('/admin/login');
    });

    it('does not let a staff login open the employee app', function () {
        $this->actingAs(User::factory()->admin()->create())->get('/')->assertRedirect(route('login'));
    });

    it('works when one browser is logged into both (e.g. an admin who also eats)', function () {
        travelToLocal('2026-11-10 07:00:00');
        menuItems('2026-11-10');
        employee(['osobne_cislo' => '4242']);

        $this->post('/prihlasenie', ['osobne_cislo' => '4242', 'pin' => '1234'])->assertRedirect(route('home'));
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/')->assertOk()->assertSee('Obed');
        $this->get('/admin')->assertOk();
    });

    it('locks a deactivated staff user out of an open session', function () {
        $user = User::factory()->kitchen()->create();
        $this->actingAs($user)->get('/admin')->assertOk();
        $user->update(['is_active' => false]);
        $this->actingAs($user->fresh())->get('/admin')->assertForbidden();
    });

    it('does not render the ordering screen for guests', function () {
        Livewire::test(OrderScreen::class)->assertStatus(401);
    });
});

describe('headers', function () {
    it('sends security headers on the employee app, the staff panel and error pages', function (string $url) {
        $r = $this->get($url);
        expect($r->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($r->headers->get('X-Frame-Options'))->toBe('DENY')
            ->and($r->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
            ->and($r->headers->get('Content-Security-Policy'))->toBe(SecurityHeaders::CSP)
            ->and($r->headers->get('Permissions-Policy'))->toContain('camera=()')
            ->and($r->headers->has('Strict-Transport-Security'))->toBeFalse(); // not in testing
    })->with(['/prihlasenie', '/admin/login', '/neexistuje']);

    it('sends HSTS in production', function () {
        $this->app->detectEnvironment(fn () => 'production');
        expect($this->get('/prihlasenie')->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000; includeSubDomains');
    });

    it('shows Slovak error pages', function () {
        $this->get('/neexistuje')->assertNotFound()->assertSee('Stránka sa nenašla')->assertSee('Späť na úvod');
        $this->actingAs(User::factory()->kitchen()->create())->get('/admin/users')->assertForbidden()->assertSee('Prístup zamietnutý');
    });
});

describe('brute force', function () {
    it('locks an osobné číslo after 10 failures even when they come from different IPs', function () {
        employee(['osobne_cislo' => '777']);

        foreach (range(1, 10) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.$i"])
                ->post('/prihlasenie', ['osobne_cislo' => '777', 'pin' => '0000'])
                ->assertSessionHasErrors(['osobne_cislo' => 'Nesprávne osobné číslo alebo PIN.']);
        }

        // correct PIN from a brand-new IP is still refused while locked
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->post('/prihlasenie', ['osobne_cislo' => '777', 'pin' => '1234'])
            ->assertSessionHasErrors('osobne_cislo');
        expect(session('errors')->first('osobne_cislo'))->toBe('Príliš veľa neúspešných pokusov. Skúste to znova o 15 min.');
        $this->assertGuest('employee');

        // other employees are not affected
        employee(['osobne_cislo' => '778']);
        $this->post('/prihlasenie', ['osobne_cislo' => '778', 'pin' => '1234'])->assertRedirect(route('home'));

        // after 15 minutes it works again
        $this->travel(16)->minutes();
        auth('employee')->logout();
        $this->post('/prihlasenie', ['osobne_cislo' => '777', 'pin' => '1234'])->assertRedirect(route('home'));
    });

    it('throttles the staff login', function () {
        User::factory()->admin()->create(['email' => 'a@b.sk']);

        $page = Livewire::test(Login::class);
        foreach (range(1, 5) as $i) {
            $page->fillForm(['email' => 'a@b.sk', 'password' => 'zle-heslo'])->call('authenticate')->assertHasFormErrors(['email']);
        }
        $page->fillForm(['email' => 'a@b.sk', 'password' => 'password'])->call('authenticate')->assertNotified();
        $this->assertGuest('web');
    });
});

it('does not load avatars (staff names) from third-party services', function () {
    $html = $this->actingAs(User::factory()->admin()->create(['name' => 'Roman Kráľ']))->get('/admin')->assertOk()->getContent();

    expect($html)->not->toContain('ui-avatars.com')->toContain('data:image/svg+xml;base64,');
});
