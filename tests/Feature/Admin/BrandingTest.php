<?php

use App\Filament\Auth\BrandedAppAuthentication;
use App\Filament\Pages\OrderingSettings;
use App\Models\User;
use App\Support\Settings;
use Livewire\Livewire;

/*
 * The organisation name is not in the code: it comes from the admin setting, else APP_COMPANY.
 */

beforeEach(fn () => $this->withoutVite());

function brandingSlips(): string
{
    $e = employee();

    return test()->actingAs(User::factory()->admin()->create())
        ->get(route('admin.slips', ['zamestnanci' => $e->id]))->assertOk()->getContent();
}

it('shows no organisation name on a fresh installation', function () {
    config(['app.company' => null]);

    expect($this->get('/manifest.webmanifest')->json('name'))->toBe('Jedáleň')
        ->and(brandingSlips())->toContain('<div class="sub">objednávanie stravy pre zamestnancov')
        ->not->toContain('KB SPA');
});

it('uses APP_COMPANY until the name is set in the admin', function () {
    config(['app.company' => 'Hotel Lipa']);

    expect($this->get('/manifest.webmanifest')->json('description'))->toBe('Aplikácia pre zamestnancov – Hotel Lipa')
        ->and(brandingSlips())->toContain('Hotel Lipa – objednávanie stravy pre zamestnancov');
    $this->get('/prihlasenie')->assertSee('Hotel Lipa');
    expect(app(BrandedAppAuthentication::class)->getBrandName())->toBe('Jedáleň Hotel Lipa');
});

it('lets the admin set or clear the name, overriding APP_COMPANY', function () {
    config(['app.company' => 'Hotel Lipa']);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(OrderingSettings::class)
        ->assertSet('company', 'Hotel Lipa')
        ->set('company', '  KB SPA ')->call('save')->assertHasNoErrors();
    app()->forgetScopedInstances();
    expect(app(Settings::class)->companyName())->toBe('KB SPA')
        ->and($this->get('/manifest.webmanifest')->json('description'))->toBe('Aplikácia pre zamestnancov – KB SPA');

    Livewire::test(OrderingSettings::class)->set('company', '')->call('save');
    app()->forgetScopedInstances();
    expect(app(Settings::class)->companyName())->toBeNull();          // deliberately empty beats .env
    $this->get('/prihlasenie')->assertDontSee('Hotel Lipa');
});

it('presets the former hard-coded name only on installations that already have employees', function () {
    $migration = require database_path('migrations/2026_11_10_000400_preset_company_name.php');
    config(['app.company' => null]);

    $migration->up();                                                  // fresh: nothing set
    expect(app(Settings::class)->companyName())->toBeNull();

    employee();
    $migration->up();
    app()->forgetScopedInstances();
    expect(app(Settings::class)->companyName())->toBe('KB SPA');

    app(Settings::class)->set(Settings::COMPANY_NAME, 'Iné');           // never overwrites an edited value
    $migration->up();
    app()->forgetScopedInstances();
    expect(app(Settings::class)->companyName())->toBe('Iné');
});

describe('app name (home screen)', function () {
    it('defaults to APP_NAME and is used for the installed app, the header and the login sheets', function () {
        config(['app.name' => 'Jedáleň']);
        $manifest = $this->get('/manifest.webmanifest')->json();
        expect([$manifest['name'], $manifest['short_name']])->toBe(['Jedáleň', 'Jedáleň']);

        app(Settings::class)->set(Settings::APP_NAME, 'KB Zamestnanci');
        $manifest = $this->get('/manifest.webmanifest')->json();
        expect([$manifest['name'], $manifest['short_name']])->toBe(['KB Zamestnanci', 'KB Zamestnanci'])
            ->and(brandingSlips())->toContain('<div class="app">KB Zamestnanci</div>')
            ->toContain('ikonou <em>KB Zamestnanci</em>');

        $this->actingAs(employee(), 'employee')->get('/ucet')
            ->assertSee('<meta name="apple-mobile-web-app-title" content="KB Zamestnanci">', false)
            ->assertSee('ikonou <em>KB Zamestnanci</em>', false);
    });

    it('is set in Nastavenia → Objednávanie and cannot be empty', function () {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(OrderingSettings::class)->set('appName', '')->call('save')->assertHasErrors('appName');
        Livewire::test(OrderingSettings::class)->set('appName', ' KB Zamestnanci ')->call('save')->assertHasNoErrors();
        app()->forgetScopedInstances();

        expect(app(Settings::class)->appName())->toBe('KB Zamestnanci');
    });

    it('presets "KB Zamestnanci" only on installations that already have employees', function () {
        $migration = require database_path('migrations/2026_11_13_000100_preset_app_name.php');
        config(['app.name' => 'Jedáleň']);

        $migration->up();
        expect(app(Settings::class)->appName())->toBe('Jedáleň');

        employee();
        $migration->up();
        app()->forgetScopedInstances();
        expect(app(Settings::class)->appName())->toBe('KB Zamestnanci');
    });
});
