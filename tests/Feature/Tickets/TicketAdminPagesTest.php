<?php

use App\Domain\Employees\EmployeeImport;
use App\Domain\Tickets\ClosedMonths;
use App\Domain\Tickets\EmployeeCalendar;
use App\Domain\Tickets\FinalTicketImport;
use App\Enums\PricingMode;
use App\Enums\Role;
use App\Filament\Pages\ImportFinalTickets;
use App\Filament\Pages\TicketMonth;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\MealGroups\MealGroupResource;
use App\Filament\Resources\MealGroups\Pages\ManageMealGroups;
use App\Models\Employee;
use App\Models\MealGroup;
use App\Models\Order;
use App\Models\TicketMonthCount;
use App\Models\TicketOverride;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    travelToLocal('2026-11-10 07:00:00');
    $this->admin = adminUser();
    $this->actingAs($this->admin);
});

describe('Skupiny stravníkov', function () {
    it('creates a group starting in ticket mode', function () {
        Livewire::test(ManageMealGroups::class)
            ->callAction('create', ['name' => 'Brigádnici', 'default_tickets' => 10, 'is_active' => true, 'initial_mode' => 'tickets'])
            ->assertHasNoActionErrors();

        $group = MealGroup::query()->where('name', 'Brigádnici')->sole();
        expect($group->load('modes')->modeOn('2026-11-01'))->toBe(PricingMode::Tickets);
    });

    it('switches the mode with a preview of the affected orders', function () {
        $group = MealGroup::query()->create(['name' => '8 h', 'default_tickets' => 1, 'is_active' => true]);
        $emp = joinGroup(employee(), $group);
        $items = menuItems('2026-11-12', lunch(), 2);
        orderAll($emp, [$items[0], $items[1]]);

        // 1 ticket, 2 meals: the mode of both orders changes, the prices stay.
        expect((string) MealGroupResource::previewText($group, 'tickets', '2026-11-11'))
            ->toBe('Prepočítajú sa 2 objednávky u 1 zamestnanca: 7,50 € → 7,50 € (rozdiel 0,00 €).')
            ->and((string) MealGroupResource::previewText($group, 'tickets', '2026-11-13'))->toBe('Žiadna objednaná cena sa nezmení.');

        Livewire::test(ManageMealGroups::class)
            ->callTableAction('switchMode', $group, ['mode' => 'tickets', 'effective_from' => '2026-11-11'])
            ->assertNotified('Režim zmenený');

        expect(prices($emp))->toBe([150, 600])
            ->and(Order::query()->pluck('pricing_mode')->unique()->all())->toBe([PricingMode::Tickets]);
    });

    it('is hidden from kitchen and read-only for payroll', function () {
        $this->actingAs(User::factory()->kitchen()->create())->get('/admin/meal-groups')->assertForbidden();
        $this->actingAs(User::factory()->withRoles(Role::Payroll)->create())->get('/admin/meal-groups')->assertOk()->assertDontSee('Zmeniť režim');
    });
});

describe('Lístky na mesiac', function () {
    beforeEach(function () {
        $this->group = ticketGroup(20);
        $this->emp = joinGroup(employee(['last_name' => 'Hudák']), $this->group);
        $this->orders = orderAll($this->emp, [firstItem('2026-11-10'), firstItem('2026-11-11'), firstItem('2026-11-12')]);
    });

    it('prefills from last month and saves the group count without asking when no price changes', function () {
        TicketMonthCount::query()->create(['meal_group_id' => $this->group->id, 'month' => '2026-10', 'tickets' => 18]);

        Livewire::test(TicketMonth::class)
            ->assertSet('counts.'.$this->group->id, 18)
            ->assertSee('Hudák')
            ->call('review')
            ->assertSet('pending', null)
            ->assertNotified('Lístky uložené');

        expect(TicketMonthCount::query()->where('month', '2026-11')->value('tickets'))->toBe(18);
    });

    it('asks before saving a change that reprices orders, then reprices', function () {
        Livewire::test(TicketMonth::class)
            ->set('overrides.'.$this->emp->id, '1')
            ->call('review')
            ->assertSee('Prepočítajú sa 2 objednávky u 1 zamestnanca')
            ->call('confirmSave')
            ->assertNotified('Lístky uložené');

        expect(prices($this->emp))->toBe([150, 600, 600])
            ->and(TicketOverride::query()->sole()->source)->toBe('manual');
    });

    it('closes and reopens the month (reason required)', function () {
        Livewire::test(TicketMonth::class)->callAction('closeMonth');
        expect(app(ClosedMonths::class)->isClosed('2026-11'))->toBeTrue();

        Livewire::test(TicketMonth::class)
            ->assertSee('Mesiac je uzavretý')
            ->callAction('reopenMonth', ['reason' => ''])->assertHasActionErrors(['reason' => 'required']);
        expect(app(ClosedMonths::class)->isClosed('2026-11'))->toBeTrue();

        Livewire::test(TicketMonth::class)->callAction('reopenMonth', ['reason' => 'oprava'])->assertNotified();
        expect(app(ClosedMonths::class)->isClosed('2026-11'))->toBeFalse();
    });

    it('is read-only for payroll', function () {
        $this->actingAs(User::factory()->withRoles(Role::Payroll)->create());

        Livewire::test(TicketMonth::class)
            ->assertSee('Hudák')
            ->assertActionHidden('closeMonth')
            ->call('review')
            ->assertForbidden();
    });
});

describe('Konečné lístky (import)', function () {
    beforeEach(function () {
        $this->group = ticketGroup(20);
        $this->a = joinGroup(employee(['osobne_cislo' => '0101', 'last_name' => 'Anna']), $this->group);
        $this->b = joinGroup(employee(['osobne_cislo' => '0102', 'last_name' => 'Boris']), $this->group);
        $this->daily = employee(['osobne_cislo' => '0103']);
        orderAll($this->a, [firstItem('2026-11-10'), firstItem('2026-11-11'), firstItem('2026-11-12')]);
        // (no time travel past "today": Livewire treats older temporary uploads as expired)
    });

    it('previews per employee with errors and missing people, then saves, reprices and closes the month', function () {
        $csv = "osobne_cislo;listky;priezvisko\n0101;2;Anna\n9999;5;Nikto\n0103;4;Denný\n0101;3;Duplicitný\n";

        $page = Livewire::test(ImportFinalTickets::class)
            ->assertSet('month', '2026-10') // early in a month the previous one is offered
            ->set('month', '2026-11')
            ->set('file', UploadedFile::fake()->createWithContent('listky.csv', $csv))
            ->call('analyze')
            ->assertHasNoErrors()
            ->assertSee('Neznáme osobné číslo 9999.')
            ->assertSee('Zamestnanec 0103 nie je v november 2026 v režime lístkov.')
            ->assertSee('je v súbore viackrát')
            ->assertSee('V súbore chýbajú 1 zamestnanci')
            ->assertSee('Boris');

        $row = $page->get('preview')['rows'][0];
        expect($row)->toMatchArray(['osobne_cislo' => '0101', 'old' => 20, 'new' => 2, 'used' => 3, 'changed' => 1, 'before' => 450, 'after' => 900])
            ->and(prices($this->a))->toBe([150, 150, 150]); // nothing saved yet

        $page->call('saveImport')->assertNotified('Konečné lístky uložené')->assertSet('saved', '2026-11');
        expect(prices($this->a))->toBe([150, 150, 600])
            ->and(TicketOverride::query()->sole()->only(['tickets', 'source']))->toBe(['tickets' => 2, 'source' => 'import'])
            ->and(Activity::query()->where('event', 'repriced')->sole()->properties['cause'])->toBe('month_import');

        $page->call('closeMonth');
        expect(app(ClosedMonths::class)->isClosed('2026-11'))->toBeTrue();
    });

    it('rejects negative and non-integer counts and reads XLSX-style decimals', function () {
        Livewire::test(ImportFinalTickets::class)
            ->set('month', '2026-11')
            ->set('file', UploadedFile::fake()->createWithContent('l.csv', "osobne_cislo,listky\n0101,-1\n0102,2.5\n"))
            ->call('analyze')
            ->assertSee('Počet lístkov musí byť celé nezáporné číslo (je „-1“).')
            ->assertSee('(je „2.5“)');

        $preview = Livewire::test(ImportFinalTickets::class)
            ->set('month', '2026-11')
            ->set('file', UploadedFile::fake()->createWithContent('l.csv', "osobne_cislo\tlistky\n0102\t19,0\n"))
            ->call('analyze')->get('preview');
        expect($preview['rows'][0]['new'])->toBe(19);
    });

    it('refuses a closed month', function () {
        app(ClosedMonths::class)->close('2026-11', $this->admin);

        Livewire::test(ImportFinalTickets::class)
            ->set('month', '2026-11')
            ->set('file', UploadedFile::fake()->createWithContent('l.csv', "osobne_cislo;listky\n0101;2\n"))
            ->call('analyze')
            ->assertHasErrors('month');
    });

    it('reads the sample files from docs/samples', function () {
        $group = MealGroup::query()->where('name', '12 h')->sole();
        foreach (range(2001, 2020) as $no) {
            joinGroup(employee(['osobne_cislo' => (string) $no]), $group);
        }

        foreach (['csv', 'xlsx'] as $ext) {
            $preview = app(FinalTicketImport::class)
                ->preview(base_path("docs/samples/konecne_listky_2026-11.$ext"), "konecne_listky_2026-11.$ext", '2026-11', $this->admin);
            expect($preview['rows'])->toHaveCount(20)->and($preview['errors'])->toBe([]);
        }

        MealGroup::query()->create(['name' => '8 h', 'default_tickets' => 0, 'is_active' => true]);
        $groups = app(EmployeeImport::class)->preview(base_path('docs/samples/zamestnanci_skupiny.csv'), 'zamestnanci_skupiny.csv');
        expect($groups['errors'])->toHaveCount(10) // 2021–2030 do not exist in this test (and have no names)
            ->and(collect($groups['rows'])->pluck('action')->unique()->all())->toBe(['unchanged']); // already in "12 h"
    });

    it('offers a template', function () {
        $this->get('/admin/sablona/konecne-listky')->assertOk()->assertSee('osobne_cislo;listky', false);
    });
});

describe('employees', function () {
    it('sets the group on the employee form, from the 1st of next month by default', function () {
        $group = ticketGroup(20);
        $emp = employee();

        Livewire::test(EditEmployee::class, ['record' => $emp->getRouteKey()])
            ->assertSet('data.group_from', fn ($v) => str_starts_with((string) $v, '2026-12-01'))
            ->fillForm(['meal_group_id' => $group->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $calendar = EmployeeCalendar::for($emp);
        expect($calendar->groupOn('2026-11-30'))->toBeNull()
            ->and($calendar->groupOn('2026-12-01')?->id)->toBe($group->id);

        // Reopening the form shows the planned group and its date.
        Livewire::test(EditEmployee::class, ['record' => $emp->getRouteKey()])
            ->assertSet('data.meal_group_id', $group->id)
            ->assertSet('data.group_from', fn ($v) => str_starts_with((string) $v, '2026-12-01'));
    });

    it('puts a new employee into the group from this month', function () {
        $group = ticketGroup(20);

        Livewire::test(CreateEmployee::class)
            ->fillForm(['osobne_cislo' => '7777', 'last_name' => 'Nový', 'first_name' => 'Ján', 'is_active' => true, 'meal_group_id' => $group->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $emp = Employee::query()->where('osobne_cislo', '7777')->sole();
        expect(EmployeeCalendar::for($emp)->groupOn('2026-11-10')?->id)->toBe($group->id);
    });

    it('shows and filters by today\'s group', function () {
        $group = ticketGroup(20);
        $in = joinGroup(employee(['last_name' => 'Vgrupe']), $group);
        $out = employee(['last_name' => 'Mimo']);

        Livewire::test(ListEmployees::class)
            ->assertSee('12 h')
            ->filterTable('meal_group', $group->id)
            ->assertCanSeeTableRecords([$in])
            ->assertCanNotSeeTableRecords([$out])
            ->filterTable('meal_group', 'none')
            ->assertCanSeeTableRecords([$out])
            ->assertCanNotSeeTableRecords([$in]);
    });

    it('imports the group column: unknown names are errors, changes apply from next month', function () {
        $group = ticketGroup(20);
        $existing = employee(['osobne_cislo' => '500']);
        $csv = "osobne_cislo;priezvisko;meno;skupina\n500;;;12 H\n501;Nová;Eva;12 h\n502;Iný;Ivan;Neexistuje\n";
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, $csv);

        $preview = app(EmployeeImport::class)->preview($path, 'z.csv');
        expect($preview['errors'][0]['messages'][0])->toContain('Neznáma skupina „Neexistuje“')
            ->and($preview['rows'][0]['changes']['group']['new'])->toBe('12 h (od 1. 12. 2026)');

        app(EmployeeImport::class)->apply($preview, by: $this->admin);
        $new = Employee::query()->where('osobne_cislo', '501')->sole();
        expect(EmployeeCalendar::for($existing)->groupOn('2026-11-30'))->toBeNull()
            ->and(EmployeeCalendar::for($existing)->groupOn('2026-12-01')?->id)->toBe($group->id)
            ->and(EmployeeCalendar::for($new)->groupOn('2026-11-10')?->id)->toBe($group->id);
    });
});
