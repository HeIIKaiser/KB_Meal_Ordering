<?php

use App\Domain\Employees\EmployeeImport;
use App\Domain\Tickets\ClosedMonths;
use App\Enums\PricingMode;
use App\Enums\Role;
use App\Filament\Resources\ClosedDays\Pages\ManageClosedDays;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\FixedPrices\Pages\ManageFixedPrices;
use App\Filament\Resources\MealGroups\Pages\ManageMealGroups;
use App\Filament\Resources\Prices\Pages\ManagePrices;
use App\Livewire\OrderScreen;
use App\Models\FixedPrice;
use App\Models\MealGroup;
use App\Models\MealGroupMode;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    travelToLocal('2026-11-12 07:00:00');
    $this->admin = adminUser();
    $this->actingAs($this->admin);
});

function szcoGroup(): MealGroup
{
    $g = MealGroup::query()->create(['name' => 'SZČO', 'settlement' => 'invoice', 'is_active' => true]);
    MealGroupMode::query()->create(['meal_group_id' => $g->id, 'mode' => PricingMode::Fixed, 'effective_from' => '2026-11-01']);

    return $g;
}

describe('Pevné ceny', function () {
    it('creates a group price and a personal lunch price; only future versions can be edited', function () {
        $g = szcoGroup();
        $e = joinGroup(employee(['last_name' => 'Živnostník']), $g, '2026-11-01');

        Livewire::test(ManageFixedPrices::class)
            ->callAction('create', ['for' => 'group', 'meal_group_id' => $g->id, 'amount_cents' => '5,00', 'valid_from' => '2026-11-01'])
            ->assertHasNoActionErrors()
            ->callAction('create', ['for' => 'employee', 'employee_id' => $e->id, 'meal_slot_id' => lunch()->id, 'amount_cents' => '4,50', 'valid_from' => '2026-12-01'])
            ->assertHasNoActionErrors();

        $group = FixedPrice::query()->whereNull('employee_id')->sole();
        $person = FixedPrice::query()->whereNotNull('employee_id')->sole();
        expect([$group->meal_group_id, $group->amount_cents, $person->meal_group_id, $person->amount_cents, $person->meal_slot_id])
            ->toBe([$g->id, 500, null, 450, lunch()->id])
            ->and($this->admin->can('update', $group))->toBeFalse()   // in effect
            ->and($this->admin->can('update', $person))->toBeTrue();  // future
    });

    it('is readable by payroll, hidden from the kitchen', function () {
        $this->actingAs(User::factory()->withRoles(Role::Payroll)->create())->get('/admin/fixed-prices')->assertOk();
        $this->actingAs(User::factory()->kitchen()->create())->get('/admin/fixed-prices')->assertForbidden();
    });

    it('keeps the fixed type out of the price list', function () {
        Livewire::test(ManagePrices::class)->assertDontSee('Pevná cena');
    });
});

describe('group and employee forms', function () {
    it('saves estimate source, settlement and the cap on the group', function () {
        Livewire::test(ManageMealGroups::class)
            ->callAction('create', ['name' => 'Brigádnici', 'default_tickets' => 0, 'mandatory_per_day' => 2, 'estimate_source' => 'orders',
                'settlement' => 'payroll', 'is_active' => true, 'initial_mode' => 'tickets'])
            ->callAction('create', ['name' => 'SZČO', 'default_tickets' => 0, 'mandatory_per_day' => 2, 'estimate_source' => 'plan',
                'settlement' => 'invoice', 'max_per_slot' => 1, 'is_active' => true, 'initial_mode' => 'fixed'])
            ->assertHasNoActionErrors();

        expect(MealGroup::query()->where('name', 'Brigádnici')->sole()->estimatesByOrders())->toBeTrue()
            ->and(MealGroup::query()->where('name', 'SZČO')->sole()->only(['settlement', 'max_per_slot']))->toBe(['settlement' => 'invoice', 'max_per_slot' => 1])
            ->and(MealGroup::query()->where('name', 'SZČO')->sole()->load('modes')->modeOn('2026-11-12'))->toBe(PricingMode::Fixed);
    });

    it('shows billing details for invoiced groups, validates and saves them, and shows the fixed price', function () {
        $g = szcoGroup();
        FixedPrice::query()->create(['meal_group_id' => $g->id, 'amount_cents' => 500, 'valid_from' => '2026-01-01']);
        $e = joinGroup(employee(), $g, '2026-11-01');

        Livewire::test(EditEmployee::class, ['record' => $e->getRouteKey()])
            ->assertSee('Fakturačné údaje')
            ->assertSee('Obed 5,00 €')
            ->fillForm(['ico' => '12', 'dic' => 'x'])->call('save')->assertHasFormErrors(['ico', 'dic'])
            ->fillForm(['billing_name' => 'Ján Živnostník', 'ico' => '12345678', 'dic' => '1234567890', 'billing_email' => 'jan@example.sk'])
            ->call('save')->assertHasNoFormErrors();

        expect($e->fresh()->only(['billing_name', 'ico', 'dic']))->toBe(['billing_name' => 'Ján Živnostník', 'ico' => '12345678', 'dic' => '1234567890']);

        $other = employee();
        Livewire::test(EditEmployee::class, ['record' => $other->getRouteKey()])->assertDontSee('Fakturačné údaje');
    });

    it('lets the admin change osobné číslo (unique)', function () {
        $e = employee(['osobne_cislo' => '0500']);
        employee(['osobne_cislo' => '0600']);

        Livewire::test(EditEmployee::class, ['record' => $e->getRouteKey()])
            ->fillForm(['osobne_cislo' => '0600'])->call('save')->assertHasFormErrors(['osobne_cislo' => 'unique'])
            ->fillForm(['osobne_cislo' => '0777'])->call('save')->assertHasNoFormErrors();
        expect($e->fresh()->osobne_cislo)->toBe('0777');
    });

    it('imports billing columns and warns about a new number with an existing name', function () {
        employee(['osobne_cislo' => '0123', 'last_name' => 'Kováč', 'first_name' => 'Peter']);
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, "osobne_cislo;priezvisko;meno;ico;email\n0999;KOVÁČ;Peter;12345678;p@example.sk\n0998;Nový;Ján;12;zlé\n");

        $preview = app(EmployeeImport::class)->preview($path, 'z.csv');
        expect($preview['warnings'][0]['message'])->toContain('Nové osobné číslo 0999, ale rovnaké meno má zamestnanec 0123')
            ->and($preview['errors'][0]['messages'])->toBe(['IČO má 6 až 8 číslic.', 'Neplatný e-mail pre faktúry.'])
            ->and($preview['rows'][0]['data'])->toMatchArray(['ico' => '12345678', 'billing_email' => 'p@example.sk']);
    });
});

describe('employee app', function () {
    it('fixed price: shows the price, no 6 € dialog, offers one more at the same price', function () {
        $g = szcoGroup();
        FixedPrice::query()->create(['meal_group_id' => $g->id, 'amount_cents' => 500, 'valid_from' => '2026-01-01']);
        $e = joinGroup(employee(), $g, '2026-11-01');
        $item = firstItem('2026-11-12');
        $this->actingAs($e, 'employee');

        Livewire::test(OrderScreen::class)
            ->call('choose', $item->id)
            ->assertSet('dialog', null)
            ->assertSee('Objednané · 5,00 €')
            ->call('choose', $item->id)
            ->assertSee('Objednať ďalšie rovnaké jedlo za 5,00 €');
    });

    it('temps: says the price is confirmed by attendance', function () {
        $g = ticketGroup(20, '2026-11-01', 'Brigádnici');
        $g->update(['estimate_source' => 'orders']);
        $e = joinGroup(employee(), $g, '2026-11-01');
        firstItem('2026-11-12');
        $this->actingAs($e, 'employee');

        Livewire::test(OrderScreen::class)->assertSee('Cena sa potvrdí podľa dochádzky na konci mesiaca.')->assertDontSee('Zostáva');
        orderAll($e, [firstItem('2026-11-13')]);
        $this->get('/moj-mesiac')->assertSee('Cena sa potvrdí podľa dochádzky na konci mesiaca.');
    });
});

describe('Fakturácia export', function () {
    beforeEach(function () {
        $g = szcoGroup();
        FixedPrice::query()->create(['meal_group_id' => $g->id, 'amount_cents' => 500, 'valid_from' => '2026-01-01']);
        $this->szco = joinGroup(employee(['osobne_cislo' => '0400']), $g, '2026-11-01');
        orderAll($this->szco, [firstItem('2026-11-12')]);
    });

    it('asks to confirm the estimate for an open month, then downloads; closed months download directly', function () {
        $this->actingAs(User::factory()->withRoles(Role::Payroll)->create());

        $this->get(route('exports.invoice', ['mesiac' => '2026-11', 'format' => 'fakturacia']))->assertSessionHas('invoice_estimate');
        $this->get('/admin/exporty')->assertSee('Fakturácia (SZČO)')->assertSee('Fakturácia – predbežný formát')->assertSee('Stiahnuť aj tak (odhad)');
        $this->get(route('exports.invoice', ['mesiac' => '2026-11', 'format' => 'fakturacia', 'odhad' => 1]))->assertOk()->assertDownload('fakturacia_2026-11.xlsx');

        app(ClosedMonths::class)->close('2026-11', $this->admin);
        $this->get(route('exports.invoice', ['mesiac' => '2026-11', 'format' => 'fakturacia']))->assertOk()->assertDownload();
    });

    it('is not available to the kitchen', function () {
        $this->actingAs(User::factory()->kitchen()->create())
            ->get(route('exports.invoice', ['mesiac' => '2026-11', 'format' => 'fakturacia']))->assertForbidden();
    });
});

it('shows "Platí od" as the local calendar date (no shift to the previous day)', function () {
    $g = szcoGroup();
    FixedPrice::query()->create(['meal_group_id' => $g->id, 'amount_cents' => 500, 'valid_from' => '2026-12-01']);

    Livewire::test(ManageFixedPrices::class)->assertSee('1. 12. 2026')->assertDontSee('30. 11. 2026');
    Livewire::test(ManagePrices::class)->assertSee('1. 1. 2000')->assertDontSee('31. 12. 1999');
});

it('uses sentence case in headings (Slovak)', function () {
    Livewire::test(ManageFixedPrices::class)->assertSee('Pevné ceny')->assertDontSee('Pevné Ceny');
    Livewire::test(ManageClosedDays::class)->assertSee('Uzavreté dni')->assertDontSee('Uzavreté Dni');
});
