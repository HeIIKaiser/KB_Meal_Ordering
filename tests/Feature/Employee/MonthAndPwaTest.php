<?php

use App\Domain\Ordering\Actor;
use App\Domain\Ordering\OrderService;

beforeEach(function () {
    $this->withoutVite();
    travelToLocal('2026-11-10 07:30:00');
    $this->emp = employee();
    $this->actingAs($this->emp, 'employee');
});

it('shows the month with count and total from snapshot prices', function () {
    $items = menuItems('2026-11-10', lunch(), 2);
    $dinner = menuItems('2026-11-10', dinner(), 1);
    $s = app(OrderService::class);
    $me = Actor::employee($this->emp);
    $s->place($this->emp, $items[0], $me);
    $s->place($this->emp, $items[1], $me, acceptExtra: true);
    $s->place($this->emp, $dinner[0], $me);

    $other = employee();
    $s->place($other, $items[0], Actor::employee($other));

    $this->get('/moj-mesiac')
        ->assertOk()
        ->assertSee('november 2026')
        ->assertSee('9,00 €')         // 1,50 + 6,00 + 1,50
        ->assertSee('3 jedlá')
        ->assertSee('Zamestnanecká strava: 2 ks (3,00 €)')
        ->assertSee('Dokupovaná strava: 1 ks (6,00 €)')
        ->assertSee('Cena stravy je odhadom a po porovnaní s dochádzkou sa môže zmeniť.');
});

it('shows previous months and ignores invalid month parameters', function () {
    $this->get('/moj-mesiac?mesiac=2026-10')->assertOk()->assertSee('október 2026')->assertSee('nemáte žiadne objednávky');
    $this->get('/moj-mesiac?mesiac=2026-13')->assertOk()->assertSee('november 2026');
});

it('ships an installable PWA manifest, icons and a service worker', function () {
    $manifest = $this->get('/manifest.webmanifest')->assertOk()->assertHeader('Content-Type', 'application/manifest+json')->json();

    expect($manifest['display'])->toBe('standalone')
        ->and($manifest['start_url'])->toBe('/')
        ->and(collect($manifest['icons'])->pluck('sizes')->all())->toContain('192x192', '512x512');

    foreach (['icons/icon-192.png', 'icons/icon-512.png', 'icons/icon-maskable-512.png', 'icons/apple-touch-icon.png', 'sw.js', 'offline.html'] as $file) {
        expect(file_exists(public_path($file)))->toBeTrue($file);
    }

    $this->get('/')->assertSee('rel="manifest"', false)->assertSee('apple-touch-icon', false)->assertSee('serviceWorker', false);
});
