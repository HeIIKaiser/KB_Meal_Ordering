<?php

use App\Domain\Ordering\SlotLockService;
use App\Enums\Role;
use App\Filament\Pages\KitchenDay;
use App\Filament\Widgets\TodayStats;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * Roman (2026-11-10): the admin must have full control. This test opens every panel
 * page/resource and every plain staff route as each role and fails if anything a
 * kitchen or payroll user can open is closed to an admin.
 */
function staffUrls(): array
{
    $panel = Filament::getPanel('admin');
    $urls = [];

    foreach ($panel->getResources() as $resource) {
        if (array_key_exists('index', $resource::getPages())) {
            $urls[] = $resource::getUrl('index', panel: 'admin');
        }
        if (array_key_exists('create', $resource::getPages())) {
            $urls[] = $resource::getUrl('create', panel: 'admin');
        }
    }
    foreach ($panel->getPages() as $page) {
        $urls[] = $page::getUrl(panel: 'admin');
    }

    return array_merge(array_values(array_unique($urls)), [
        route('kitchen.print', ['datum' => '2026-11-10', 'jedlo' => 'obed']),
        route('kitchen.pdf', ['datum' => '2026-11-10', 'jedlo' => 'obed']),
        route('kitchen.xlsx', ['datum' => '2026-11-10', 'jedlo' => 'obed']),
        route('menu.week.print', ['tyzden' => '2026-11-09']),
        route('menu.week.pdf', ['tyzden' => '2026-11-09']),
        route('exports.monthly', ['mesiac' => '2026-11']),
        route('exports.list', ['od' => '2026-11-01', 'do' => '2026-11-30', 'format' => 'csv']),
        route('admin.template', 'menu'),
        route('admin.template', 'zamestnanci'),
        route('admin.slips'),
    ]);
}

beforeEach(fn () => travelToLocal('2026-11-10 07:00:00'));

it('lets the admin open everything kitchen or payroll can open', function () {
    $status = [];
    foreach ([Role::Admin, Role::Kitchen, Role::Payroll] as $role) {
        $user = User::factory()->withRoles($role)->create();
        foreach (staffUrls() as $url) {
            $status[$role->value][$url] = $this->actingAs($user)->get($url)->getStatusCode();
        }
    }

    $missing = [];
    foreach ($status['admin'] as $url => $code) {
        if ($code !== 200) {
            $missing[] = "admin $code $url";
        }
    }
    foreach (['kitchen', 'payroll'] as $role) {
        foreach ($status[$role] as $url => $code) {
            if ($code === 200 && $status['admin'][$url] !== 200) {
                $missing[] = "$role can open $url, admin cannot";
            }
        }
    }

    expect($missing)->toBe([])
        ->and(count($status['admin']))->toBeGreaterThan(20);
});

it('lets the admin use the kitchen and payroll actions too', function () {
    $admin = User::factory()->admin()->create();
    menuItems('2026-11-10');

    // lock / unlock (kitchen action)
    $this->actingAs($admin);
    Livewire::test(KitchenDay::class)->assertSee('Uzamknúť')->call('lock')->assertSee('Uzamknuté');
    expect(app(SlotLockService::class)->isLocked('2026-11-10', lunch()))->toBeTrue();

    // dashboard widget (kitchen) is available to the admin (widgets load lazily, so test it directly)
    expect(TodayStats::canView())->toBeTrue();
    Livewire::test(TodayStats::class)->assertSee('Dnes – Obed');
});
