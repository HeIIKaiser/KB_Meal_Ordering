<?php

use App\Domain\Attendance\AttendanceSync;
use App\Domain\Employees\EmployeeImport;
use App\Domain\Employees\VemaNumbers;
use App\Domain\Tickets\ClosedMonths;
use App\Enums\PricingMode;
use App\Enums\Role;
use App\Filament\Pages\AttendanceSettings;
use App\Filament\Pages\AttendanceStatus;
use App\Filament\Pages\FinalizeMonth;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\MealGroups\Pages\ManageMealGroups;
use App\Filament\Widgets\AttendanceSyncStatus;
use App\Livewire\OrderScreen;
use App\Models\AttendanceResult;
use App\Models\MealGroup;
use App\Models\User;
use App\Support\Settings;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    travelToLocal('2026-11-12 08:00:00');
    $this->admin = adminUser();
    $this->actingAs($this->admin);
});

describe('settings', function () {
    it('generates a token shown once and stored only as a hash', function () {
        $page = Livewire::test(AttendanceSettings::class)->call('generateToken');
        $token = $page->get('newToken');

        expect(strlen($token))->toBe(48)
            ->and(app(Settings::class)->get(Settings::ATTENDANCE_TOKEN_HASH))->toBe(hash('sha256', $token));
        Livewire::test(AttendanceSettings::class)->assertSet('newToken', null)->assertDontSee($token);
    });

    it('saves the thresholds and the allowlist, and validates them', function () {
        Livewire::test(AttendanceSettings::class)
            ->assertSet('oneMeal', '4:00')->assertSet('twoMeals', '10:00')
            ->set('twoMeals', '3:00')->call('save')->assertHasErrors('twoMeals')
            ->set('twoMeals', '11:30')->set('allowlist', '1.2.3.4, nope')->call('save')->assertHasErrors('allowlist')
            ->set('allowlist', '203.0.113.10, 10.0.0.0/8')->call('save')->assertHasNoErrors();

        app()->forgetScopedInstances();
        expect(app(Settings::class)->int(Settings::ATTENDANCE_TWO_MEALS_SECONDS))->toBe(hm('11:30'));
    });

    it('is admin only', function () {
        $this->actingAs(User::factory()->withRoles(Role::Payroll)->create())->get(AttendanceSettings::getUrl())->assertForbidden();
    });
});

describe('Dochádzka and Finalizovať', function () {
    beforeEach(function () {
        travelToLocal('2026-10-10 07:00:00');
        $this->emp = joinGroup(employee(['osobne_cislo' => '0101', 'last_name' => 'Anna']), ticketGroup(20, '2026-10-01'), '2026-10-01');
        $this->cook = joinGroup(employee(['osobne_cislo' => '0103', 'last_name' => 'Kuchárka']), mandatoryGroup(2, '2026-10-01'), '2026-10-01');
        joinGroup(employee(['osobne_cislo' => '0104', 'last_name' => 'Chýbajúci']), MealGroup::query()->where('name', '12 h')->sole(), '2026-10-01');
        orderAll($this->emp, [firstItem('2026-10-12'), firstItem('2026-10-13')]);
        syncAttendance('2026-10', [
            '101' => ['2026-10-12' => ['8:00', '8:00'], '2026-10-13' => ['8:00', 0]],
            '103' => ['2026-10-12' => ['12:00', '12:00'], 'locked' => true],
            '555' => ['2026-10-12' => ['8:00', '8:00']],
        ]);
        travelToLocal('2026-11-12 08:00:00');
    });

    it('shows sync state, unmatched numbers, people without attendance, unfilled days and the numbers', function () {
        Livewire::test(AttendanceStatus::class, ['month' => '2026-10'])
            ->assertSee('u 1 z 2 zamestnancov')
            ->assertSee('Nepárované z dochádzky (1)')->assertSee('555')
            ->assertSee('Bez dochádzky (1)')->assertSee('Chýbajúci')
            ->assertSee('Nevyplnené plánované dni – zamestnancov: 1')->assertSee('13. 10.')
            ->assertSee('dochádzka – odhad');
    });

    it('requests a sync from the status page (admin only)', function () {
        Livewire::test(AttendanceStatus::class, ['month' => '2026-10'])->call('requestSync')->assertSee('Čaká sa na most');
        expect(app(AttendanceSync::class)->pending()['pending'])->toBeTrue();

        $this->actingAs(User::factory()->withRoles(Role::Payroll)->create());
        Livewire::test(AttendanceStatus::class)->assertDontSee('Synchronizovať teraz')->call('requestSync')->assertForbidden();
    });

    it('finalises: preview of changed people, save, then close', function () {
        $page = Livewire::test(FinalizeMonth::class)
            ->assertSet('month', '2026-10')
            ->assertSee('Pokračovať s poslednými dátami')
            ->call('makePreview')
            ->assertSee('Anna')->assertSee('nevyplnené: 13. 10.')
            ->assertDontSee('Kuchárka')            // mandatory 2 → 2: unchanged, shown only with "všetkých"
            ->set('showAll', true)->assertSee('Kuchárka')->assertSee('bez dochádzky')
            ->call('save')
            ->assertNotified('Mesiac finalizovaný')
            ->assertSet('saved', true);

        expect(prices($this->emp))->toBe([150, 600])
            ->and(AttendanceResult::query()->where('employee_id', $this->cook->id)->value('mandatory_final'))->toBe(2);

        $page->call('closeMonth')->assertRedirect();
        expect(app(ClosedMonths::class)->isClosed('2026-10'))->toBeTrue();
    });

    it('waits for a fresh sync after "Synchronizovať teraz"', function () {
        Livewire::test(FinalizeMonth::class)->call('requestSync')->assertSee('Čaká sa na most');
        syncAttendance('2026-10', ['101' => ['2026-10-12' => ['8:00', '8:00']]]);
        Livewire::test(FinalizeMonth::class)->assertSee('– čerstvé')->assertSee('Zobraziť náhľad');
    });

    it('shows the dashboard widget once a token exists, red when stale', function () {
        expect(AttendanceSyncStatus::canView())->toBeFalse();
        app(Settings::class)->set(Settings::ATTENDANCE_TOKEN_HASH, hash('sha256', 'x'));
        Livewire::test(AttendanceSyncStatus::class)->assertSee('Viac ako 36 h bez synchronizácie');

        syncAttendance('2026-11', []);
        Livewire::test(AttendanceSyncStatus::class)->assertSee('OK');
    });
});

describe('groups and employees', function () {
    it('creates a mandatory group with N per day', function () {
        Livewire::test(ManageMealGroups::class)
            ->callAction('create', ['name' => 'Kuchyňa', 'default_tickets' => 0, 'mandatory_per_day' => 1, 'is_active' => true, 'initial_mode' => 'mandatory'])
            ->assertHasNoActionErrors();

        $g = MealGroup::query()->where('name', 'Kuchyňa')->sole();
        expect($g->mandatory_per_day)->toBe(1)->and($g->load('modes')->modeOn('2026-11-12'))->toBe(PricingMode::Mandatory);
    });

    it('edits the Vema number and the meal cap on the employee form', function () {
        $e = employee(['osobne_cislo' => '0042']);
        employee(['osobne_cislo' => '0043', 'vema_cislo' => '43']);

        Livewire::test(EditEmployee::class, ['record' => $e->getRouteKey()])
            ->fillForm(['vema_cislo' => '4x'])->call('save')->assertHasFormErrors(['vema_cislo'])
            ->fillForm(['vema_cislo' => '43'])->call('save')->assertHasFormErrors(['vema_cislo' => 'unique'])
            ->fillForm(['vema_cislo' => '042', 'max_meals_per_day' => 1])->call('save')->assertHasNoFormErrors();

        expect($e->fresh()->only(['vema_cislo', 'max_meals_per_day']))->toBe(['vema_cislo' => '042', 'max_meals_per_day' => 1]);
    });

    it('fills missing Vema numbers from osobné číslo without leading zeros', function () {
        $a = employee(['osobne_cislo' => '003']);
        $b = employee(['osobne_cislo' => 'X12']);
        $c = employee(['osobne_cislo' => '0077', 'vema_cislo' => '9']);
        $d = employee(['osobne_cislo' => '9']); // 9 already used by $c

        Livewire::test(ListEmployees::class)->callAction('fillVema')->assertNotified('Doplnených Vema čísel: 1');

        expect([$a->fresh()->vema_cislo, $b->fresh()->vema_cislo, $c->fresh()->vema_cislo, $d->fresh()->vema_cislo])->toBe(['3', null, '9', null])
            ->and(VemaNumbers::export('003'))->toBe(3);
    });

    it('imports vema_cislo and max_jedal_za_den', function () {
        $e = employee(['osobne_cislo' => '0500']);
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, "osobne_cislo;vema_cislo;max_jedal_za_den\n0500;500;1\n0501;12x;3\n");

        $preview = app(EmployeeImport::class)->preview($path, 'z.csv');
        expect($preview['errors'][0]['messages'])->toContain('Vema číslo môže obsahovať len číslice.')
            ->toContain('Max. jedál za deň musí byť 1, 2 alebo „-“ (bez obmedzenia).');

        app(EmployeeImport::class)->apply($preview);
        expect($e->fresh()->only(['vema_cislo', 'max_meals_per_day']))->toBe(['vema_cislo' => '500', 'max_meals_per_day' => 1]);
    });
});

describe('employee app in mandatory mode', function () {
    beforeEach(function () {
        $this->emp = joinGroup(employee(['osobne_cislo' => '0029']), mandatoryGroup(2, '2026-11-01'), '2026-11-01');
        $this->actingAs($this->emp, 'employee');
    });

    it('explains that mandatory meals are charged automatically', function () {
        menuItems('2026-11-12', lunch(), 1);
        Livewire::test(OrderScreen::class)->assertSee('Povinná strava sa účtuje automaticky podľa dochádzky.');
    });

    it('shows the automatic charges on Môj mesiac', function () {
        syncAttendance('2026-11', ['29' => ['2026-11-02' => ['12:00', '12:00'], '2026-11-03' => ['12:00', '12:00']]]);

        $this->get('/moj-mesiac')->assertOk()
            ->assertSee('Povinná strava:')->assertSee('4 jedál (6,00 €)')
            ->assertSee('Povinná strava')->assertSee('odhad podľa plánu smien a dochádzky');
    });
});
