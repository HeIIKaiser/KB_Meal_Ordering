<?php

use App\Domain\Employees\Slips\SlipOptions;
use App\Domain\Employees\Slips\SlipSelection;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\Employee;
use App\Models\User;
use Livewire\Livewire;

/*
 * Access slips: the admin chooses sort order, grouping (stredisko / group / meal form),
 * a new page per section and the format; the choice is remembered per staff user.
 */

beforeEach(function () {
    travelToLocal('2026-11-25 07:00:00');
    $this->admin = adminUser();
    $this->actingAs($this->admin);
});

function slipPeople(): array
{
    $lunch = mandatoryGroup(1, '2026-10-01', 'Povinná – obed');
    $lunch->update(['import_aliases' => ['Povinná - len obed']]);
    $tickets = ticketGroup(20, '2026-10-01', 'Lístky');
    $tickets->update(['import_aliases' => ['Objednáva si']]);

    return [
        'zuzana' => joinGroup(employee(['last_name' => 'Zelená', 'first_name' => 'Zuzana', 'osobne_cislo' => '100', 'stredisko_cislo' => '14', 'department' => 'Stravovanie']), $tickets, '2026-10-01'),
        'adam' => joinGroup(employee(['last_name' => 'Adamec', 'first_name' => 'Adam', 'osobne_cislo' => '20', 'stredisko_cislo' => '2', 'department' => 'Recepcia']), $lunch, '2026-10-01'),
        'cyril' => employee(['last_name' => 'Čierny', 'first_name' => 'Cyril', 'osobne_cislo' => '3', 'stredisko_cislo' => '14', 'department' => 'Stravovanie']),
        'boris' => joinGroup(employee(['last_name' => 'Beňo', 'first_name' => 'Boris', 'osobne_cislo' => '11', 'department' => null]), $tickets, '2026-10-01'),
    ];
}

function slipsPage(array $people, array $options = []): string
{
    return test()->get(route('admin.slips', ['zamestnanci' => collect($people)->pluck('id')->implode(','), ...$options]))->assertOk()->getContent();
}

describe('sort order', function () {
    it('sorts by name (default), natural osobné číslo, stredisko and group', function (array $options, array $expected) {
        slipPeople();
        test()->get(route('admin.slips', ['zamestnanci' => Employee::query()->pluck('id')->implode(','), ...$options]))
            ->assertSeeInOrder($expected);
    })->with([
        'name' => [[], ['Adam Adamec', 'Boris Beňo', 'Cyril Čierny', 'Zuzana Zelená']],
        'natural number' => [['zoradit' => 'cislo'], ['Cyril Čierny', 'Boris Beňo', 'Adam Adamec', 'Zuzana Zelená']], // 3, 11, 20, 100
        'stredisko' => [['zoradit' => 'stredisko'], ['Adam Adamec', 'Cyril Čierny', 'Zuzana Zelená', 'Boris Beňo']], // 2, 14, 14, none
        'group' => [['zoradit' => 'skupina'], ['Boris Beňo', 'Zuzana Zelená', 'Adam Adamec', 'Cyril Čierny']],      // Lístky, Povinná, none
    ]);

    it('sorts osobné číslo 1, 2, 10, 11, 20, 100', function () {
        foreach (['100', '11', '2', '20', '1', '10'] as $n) {
            employee(['osobne_cislo' => $n, 'last_name' => 'Číslo', 'first_name' => 'N'.$n]);
        }
        test()->get(route('admin.slips', ['zamestnanci' => Employee::query()->pluck('id')->implode(','), 'zoradit' => 'cislo', 'format' => 'male']))
            ->assertSeeInOrder(['N1 Číslo', 'N2 Číslo', 'N10 Číslo', 'N11 Číslo', 'N20 Číslo', 'N100 Číslo']);
    });
});

describe('grouping', function () {
    it('groups by stredisko with number, name and count; people without one last', function () {
        $p = slipPeople();
        test()->get(route('admin.slips', ['zamestnanci' => collect($p)->pluck('id')->implode(','), 'skupiny' => 'stredisko', 'format' => 'male']))
            ->assertSeeInOrder(['Stredisko 2 – Recepcia (1)', 'Adam Adamec', 'Stredisko 14 – Stravovanie (2)', 'Cyril Čierny', 'Zuzana Zelená', 'Bez strediska (1)', 'Boris Beňo']);
    });

    it('groups by meal group; people without a group form „Bez skupiny“', function () {
        $p = slipPeople();
        test()->get(route('admin.slips', ['zamestnanci' => collect($p)->pluck('id')->implode(','), 'skupiny' => 'skupina', 'zoradit' => 'cislo', 'format' => 'male']))
            ->assertSeeInOrder(['Skupina: Lístky (2)', 'Boris Beňo', 'Zuzana Zelená', 'Skupina: Povinná – obed (1)', 'Adam Adamec', 'Bez skupiny (1)', 'Cyril Čierny']);
    });

    it('groups by meal form using the HR sheet name („Názvy v importe“)', function () {
        $p = slipPeople();
        test()->get(route('admin.slips', ['zamestnanci' => collect($p)->pluck('id')->implode(','), 'skupiny' => 'forma', 'format' => 'male']))
            ->assertSeeInOrder(['Forma stravy: Objednáva si (2)', 'Boris Beňo', 'Zuzana Zelená', 'Forma stravy: Povinná - len obed (1)', 'Adam Adamec', 'Bez skupiny (1)', 'Cyril Čierny']);
    });

    it('starts each section on a new page only when chosen (small slips)', function () {
        $p = slipPeople();
        $with = slipsPage($p, ['skupiny' => 'stredisko', 'format' => 'male', 'strana' => '1']);
        $without = slipsPage($p, ['skupiny' => 'stredisko', 'format' => 'male', 'strana' => '0']);
        $ungrouped = slipsPage($p, ['format' => 'male', 'strana' => '1']);

        expect(substr_count($with, 'class="sheet section-break"'))->toBe(2) // 3 sections, the first one without a break
            ->and($without)->not->toContain('section-break"')
            ->and($ungrouped)->not->toContain('section-break"')
            ->and($ungrouped)->not->toContain('class="section-head"');
    });

    it('A4: a cover page per section only when grouping, and the section on every page', function () {
        $p = slipPeople();
        $grouped = slipsPage($p, ['skupiny' => 'stredisko']);
        $plain = slipsPage($p);

        expect(substr_count($grouped, 'section-cover'))->toBe(3)
            ->and($grouped)->toContain('Stredisko 14 – Stravovanie (2) · ')
            ->and($grouped)->toContain('Tlačiť (7 strán A4)')
            ->and($plain)->not->toContain('section-cover')
            ->and($plain)->toContain('Tlačiť (4 strany A4)');
    });
});

describe('parameters', function () {
    it('falls back to the defaults for invalid parameters', function () {
        $o = SlipOptions::fromArray(['zoradit' => 'DROP TABLE', 'skupiny' => ['x'], 'strana' => 'áno', 'format' => '<script>']);
        expect($o->toQuery())->toBe(['zoradit' => 'meno', 'skupiny' => 'nie', 'strana' => 1, 'format' => 'a4']);

        $p = slipPeople();
        test()->get(route('admin.slips', ['zamestnanci' => collect($p)->pluck('id')->implode(','), 'zoradit' => 'xyz', 'skupiny' => 'xyz', 'format' => 'xyz']))
            ->assertOk()
            ->assertSee('class="page"', false)                         // A4
            ->assertDontSee('section-cover')
            ->assertSeeInOrder(['Adam Adamec', 'Boris Beňo', 'Cyril Čierny', 'Zuzana Zelená']);
    });

    it('keeps short selections in the URL (bookmarkable) and prints 1000 employees via a short token URL', function () {
        $ids = collect(range(1, 1000))->map(fn ($i) => employee(['osobne_cislo' => (string) $i, 'last_name' => 'P'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)])->id)->all();

        expect(EmployeeResource::slipsUrl(array_slice($ids, 0, 3)))->toContain('zamestnanci=');
        $url = EmployeeResource::slipsUrl($ids, new SlipOptions(sort: 'cislo', format: 'male'));
        expect($url)->toContain('vyber=')->not->toContain('zamestnanci=')
            ->and(strlen($url))->toBeLessThan(200);

        $html = test()->get($url)->assertOk()->getContent();
        expect(substr_count($html, 'class="slip"'))->toBe(1000)
            ->and(Employee::query()->whereNull('slip_printed_at')->count())->toBe(0);
    });

    it('accepts a selection token only for the user who created it, and only until it expires', function () {
        $ids = collect(range(1, SlipSelection::INLINE_MAX + 1))->map(fn ($i) => employee()->id)->all();
        $url = EmployeeResource::slipsUrl($ids);

        $this->actingAs(User::factory()->admin()->create());
        expect(substr_count($this->get($url)->assertOk()->getContent(), 'class="page"'))->toBe(0);

        $this->actingAs($this->admin);
        expect(substr_count($this->get($url)->getContent(), 'class="page"'))->toBe(SlipSelection::INLINE_MAX + 1);

        $this->travel(SlipSelection::TTL_MINUTES + 1)->minutes();
        $this->get($url)->assertOk()->assertSee('Nie sú vybraní žiadni zamestnanci');
    });

    it('ignores a malformed token', function () {
        $this->get(route('admin.slips', ['vyber' => '../../etc']))->assertOk()->assertSee('Nie sú vybraní žiadni zamestnanci');
    });
});

describe('modal and remembered choice', function () {
    it('prints from the bulk action with the chosen options and remembers them', function () {
        $p = slipPeople();

        Livewire::test(ListEmployees::class)
            ->mountTableBulkAction('printSlips', [$p['adam'], $p['zuzana']])
            ->assertTableBulkActionDataSet(['zoradit' => 'meno', 'skupiny' => 'nie', 'format' => 'a4'])
            ->setTableBulkActionData(['zoradit' => 'cislo', 'skupiny' => 'forma', 'strana' => false, 'format' => 'male'])
            ->callMountedTableBulkAction()
            ->assertRedirectContains('zoradit=cislo&skupiny=forma&strana=0&format=male');

        expect($this->admin->fresh()->preferences['slips'])->toBe(['zoradit' => 'cislo', 'skupiny' => 'forma', 'strana' => 0, 'format' => 'male']);

        // next time the modal is pre-filled with the last choice; the links use it too
        Livewire::test(ListEmployees::class)
            ->mountTableBulkAction('printSlips', [$p['adam']])
            ->assertTableBulkActionDataSet(['zoradit' => 'cislo', 'skupiny' => 'forma', 'strana' => false, 'format' => 'male']);
        expect(EmployeeResource::slipsUrl([$p['adam']->id]))->toContain('skupiny=forma');

        // the single slip keeps only the format
        Livewire::test(ListEmployees::class)->assertTableActionHasUrl('printSlip', route('admin.slips', ['zamestnanci' => $p['adam']->id, 'zoradit' => 'meno', 'skupiny' => 'nie', 'strana' => 1, 'format' => 'male']), $p['adam']);
    });

    it('remembers a change made on the print page itself', function () {
        $p = slipPeople();
        $this->get(route('admin.slips', ['zamestnanci' => $p['adam']->id, 'zoradit' => 'stredisko', 'skupiny' => 'stredisko', 'strana' => '0', 'format' => 'a4', 'zapamatat' => '1']))->assertOk();

        expect(SlipOptions::forUser($this->admin->fresh())->toQuery())->toBe(['zoradit' => 'stredisko', 'skupiny' => 'stredisko', 'strana' => 0, 'format' => 'a4']);
        // remembered per user
        expect(SlipOptions::forUser(User::factory()->admin()->create())->toQuery()['zoradit'])->toBe('meno');
    });

    it('new PINs + slips: issues PINs, then prints with the chosen options', function () {
        $p = slipPeople();
        $old = $p['adam']->printablePin();

        Livewire::test(ListEmployees::class)
            ->callTableBulkAction('slips', [$p['adam']], ['zoradit' => 'cislo', 'skupiny' => 'stredisko', 'strana' => true, 'format' => 'a4'])
            ->assertRedirectContains('zoradit=cislo&skupiny=stredisko&strana=1&format=a4');
        expect($p['adam']->fresh()->printablePin())->not->toBe($old);
    });

    it('printing does not change PINs, only slip_printed_at', function () {
        $p = slipPeople();
        $before = Employee::query()->get()->mapWithKeys(fn ($e) => [$e->id => [$e->pin_hash, $e->printablePin()]])->all();
        slipsPage($p, ['skupiny' => 'skupina', 'format' => 'male']);

        expect(Employee::query()->get()->mapWithKeys(fn ($e) => [$e->id => [$e->pin_hash, $e->printablePin()]])->all())->toBe($before)
            ->and(Employee::query()->whereNull('slip_printed_at')->count())->toBe(0);
    });
});

describe('selecting people', function () {
    it('select all matching records across pages reaches the bulk print, filtered by stredisko', function () {
        ticketGroup(); // the group filters show once a group exists
        foreach (range(1, 60) as $i) {
            employee(['osobne_cislo' => (string) (500 + $i), 'department' => 'Stravovanie', 'stredisko_cislo' => '14']);
        }
        $other = employee(['department' => 'Recepcia', 'stredisko_cislo' => '2']);

        $component = Livewire::test(ListEmployees::class)
            ->assertTableFilterExists('department')
            ->assertTableFilterExists('meal_group')
            ->assertTableFilterExists('without_group')
            ->assertTableFilterExists('not_printed')
            ->filterTable('department', 'Stravovanie')
            ->set('isTrackingDeselectedTableRecords', true) // "Vybrať všetky (61)" across pages
            ->callTableBulkAction('printSlips', [], ['zoradit' => 'cislo', 'skupiny' => 'skupina', 'strana' => true, 'format' => 'male']);

        $url = $component->effects['redirect'];
        expect($url)->toContain('zamestnanci='); // 60 ≤ inline limit
        $html = $this->get($url)->assertOk()->getContent();
        expect(substr_count($html, 'class="slip"'))->toBe(60)
            ->and($html)->toContain('Bez skupiny (60)')
            ->and($other->fresh()->slip_printed_at)->toBeNull();
    });

    it('labels the Stredisko filter with number and name', function () {
        employee(['department' => 'Stravovanie', 'stredisko_cislo' => '14']);
        employee(['department' => 'Recepcia', 'stredisko_cislo' => '2']);
        employee(['department' => 'Sklad']);

        expect(EmployeeResource::departmentOptions())->toBe(['Recepcia' => '2 – Recepcia', 'Stravovanie' => '14 – Stravovanie', 'Sklad' => 'Sklad']);
    });
});
