<?php

use App\Domain\Employees\EmployeeImport;
use App\Domain\Employees\NameSplitter;
use App\Domain\Exports\MonthlyData;
use App\Domain\Exports\MonthlyMatrixXlsx;
use App\Domain\Exports\OrderListExport;
use App\Domain\Exports\Payroll\VemaFormat;
use App\Domain\Ordering\Actor;
use App\Domain\Ordering\OrderService;
use App\Domain\Tickets\ClosedMonths;
use App\Domain\Tickets\EmployeeCalendar;
use App\Enums\PricingMode;
use App\Enums\Role;
use App\Filament\Pages\ImportEmployees;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\MealGroups\Pages\ManageMealGroups;
use App\Filament\Widgets\EmployeesWithoutGroup;
use App\Models\Employee;
use App\Models\MealGroup;
use App\Models\MealGroupMode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

/*
 * Roman's master sheet (2026-11-24): Vema = osobné číslo, one "Name" column, "181-1"
 * attendance numbers, "Forma stravy" → group via "Názvy v importe", group start dates.
 */

function masterCsv(string $body, string $header = '#;Vema;Name;Workplace no.;Workplace;Dochádzka;Name in attendance;Forma stravy'): string
{
    $path = tempnam(sys_get_temp_dir(), 'master');
    file_put_contents($path, $header."\n".$body);

    return $path;
}

function masterPreview(string $path): array
{
    return app(EmployeeImport::class)->preview($path, 'zamestnanci.csv');
}

/** The three groups recommended in docs/navod.md, with the sheet's values as import names. */
function masterGroups(): array
{
    $lunch = mandatoryGroup(1, '2026-10-01', 'Povinná strava – obed');
    $lunch->update(['import_aliases' => ['Povinná - len obed']]);
    $both = mandatoryGroup(2, '2026-10-01', 'Povinná strava – obed a večera');
    $both->update(['import_aliases' => ['Povinná - obed+večera']]);
    $tickets = ticketGroup(20, '2026-10-01', 'Lístky');
    $tickets->update(['import_aliases' => ['Objednáva si']]);

    return [$lunch, $both, $tickets];
}

/** @return array<string, list<list<mixed>>> */
function masterXlsx(string $path): array
{
    $reader = new Reader;
    $reader->open($path);
    $out = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $out[$sheet->getName()][] = $row->toArray();
        }
    }
    $reader->close();

    return $out;
}

const ROMAN_SHEET = "1;181;Kováčiková Mariana;11;Administratíva;181-1;Kováčiková M.;Povinná - len obed\n"
    ."2;312;Porubský Roman;11;Administratíva;274-1;Porubský R.;Povinná - obed+večera\n"
    ."3;115;Sokolová Lenka;11;Administratíva;115-1;Sokolová L.;Objednáva si\n"
    ."4;24;Filip Hanzen Erika;12;Beauty;24-1;Filip Hanzen E.;\n"
    ."5;285;Vágner Miroslav;41;Plniareň;;;\n";

beforeEach(function () {
    travelToLocal('2026-11-24 07:00:00');
    $this->admin = adminUser();
    $this->actingAs($this->admin);
});

describe('name split', function () {
    it('takes the last word as the first name and drops academic titles', function (string $full, ?string $last, ?string $first, int $words) {
        expect(NameSplitter::split($full))->toBe(['last_name' => $last, 'first_name' => $first, 'words' => $words]);
    })->with([
        ['Kováčiková Mariana', 'Kováčiková', 'Mariana', 2],
        ['Filip Hanzen Erika', 'Filip Hanzen', 'Erika', 3],
        ['Matulová Mesiarkinová Ivana', 'Matulová Mesiarkinová', 'Ivana', 3],
        ['Ing. Novák Ján', 'Novák', 'Ján', 2],
        ['Mgr. Bc. Biela Anna, PhD.', 'Biela', 'Anna', 2],
        ['MUDr. Malá Eva', 'Malá', 'Eva', 2],
        ['PhDr.  Horák   Peter', 'Horák', 'Peter', 2],
        ['prof. Ing. arch. Dlhý Karol, CSc.', 'Dlhý', 'Karol', 2],
        ['Doc Martin', 'Doc', 'Martin', 2],          // "Doc" without a dot is a name
        ['Novák', 'Novák', null, 1],
    ]);

    it('checks the name in attendance by surname and initial', function () {
        expect(NameSplitter::matchesAttendanceName('Kováčiková M.', 'Kováčiková', 'Mariana'))->toBeTrue()
            ->and(NameSplitter::matchesAttendanceName('KOVACIKOVA M', 'Kováčiková', 'Mariana'))->toBeTrue()
            ->and(NameSplitter::matchesAttendanceName('Filip Hanzen E.', 'Filip Hanzen', 'Erika'))->toBeTrue()
            ->and(NameSplitter::matchesAttendanceName('Sokolová L.', 'Kováčiková', 'Mariana'))->toBeFalse()
            ->and(NameSplitter::matchesAttendanceName('Kováčiková L.', 'Kováčiková', 'Mariana'))->toBeFalse();
    });
});

describe('master sheet import', function () {
    it('imports Roman\'s sheet as-is: Vema as key, split names, 181-1, cost centre, groups from today', function () {
        [$lunch, $both, $tickets] = masterGroups();
        $preview = masterPreview(masterCsv(ROMAN_SHEET));

        expect($preview['errors'])->toBe([])
            ->and($preview['key_from_vema'])->toBeTrue()
            ->and(collect($preview['rows'])->pluck('osobne_cislo')->all())->toBe(['181', '312', '115', '24', '285'])
            ->and($preview['rows'][3]['split'])->toBe(['last_name' => 'Filip Hanzen', 'first_name' => 'Erika'])
            ->and($preview['rows'][0]['data'])->toMatchArray([
                'last_name' => 'Kováčiková', 'first_name' => 'Mariana', 'department' => 'Administratíva',
                'stredisko_cislo' => '11', 'vema_cislo' => '181', 'attendance_cislo' => '181',
            ])
            ->and($preview['rows'][1]['data']['attendance_cislo'])->toBe('274')
            ->and($preview['rows'][4]['data'])->not->toHaveKey('attendance_cislo')
            ->and($preview['group_counts'])->toBe(['Lístky' => 1, 'Povinná strava – obed' => 1, 'Povinná strava – obed a večera' => 1])
            ->and(collect($preview['unset'])->pluck('osobne_cislo')->all())->toBe(['24', '285'])
            ->and(collect($preview['warnings'])->pluck('message')->join("\n"))->toContain('Filip Hanzen Erika')->toContain('viac slov');

        $result = app(EmployeeImport::class)->apply($preview, by: $this->admin);
        expect($result['created'])->toBe(5)->and($result['groups'])->toBe(3);

        $m = Employee::query()->where('osobne_cislo', '181')->sole();
        expect([$m->last_name, $m->first_name, $m->vema_cislo, $m->attendance_cislo, $m->stredisko_cislo, $m->department])
            ->toBe(['Kováčiková', 'Mariana', '181', '181', '11', 'Administratíva'])
            ->and($m->printablePin())->toMatch('/^\d{6}$/')
            ->and(EmployeeCalendar::for($m)->groupOn('2026-11-23'))->toBeNull()
            ->and(EmployeeCalendar::for($m)->groupOn('2026-11-24')?->id)->toBe($lunch->id)
            ->and(EmployeeCalendar::for(Employee::query()->where('osobne_cislo', '312')->sole())->groupOn('2026-11-24')?->id)->toBe($both->id);

        // Empty "Forma stravy": saved, no group → daily mode, so they can still order.
        $vagner = Employee::query()->where('osobne_cislo', '285')->sole();
        expect($vagner->is_active)->toBeTrue()
            ->and(EmployeeCalendar::for($vagner)->modeOn('2026-11-24'))->toBe(PricingMode::Daily)
            ->and(Employee::query()->withoutGroup()->pluck('osobne_cislo')->sort()->values()->all())->toBe(['24', '285']);
    });

    it('tolerates English/Slovak headers with diacritics, dots, spaces and "no."', function () {
        $path = masterCsv("181;Kováčiková Mariana;11;Administratíva;181-1\n", 'VEMA;Meno a priezvisko;Číslo strediska;Pracovisko;Dochádzka č.');
        $row = masterPreview($path)['rows'][0];
        expect($row['data'])->toMatchArray(['last_name' => 'Kováčiková', 'first_name' => 'Mariana', 'stredisko_cislo' => '11', 'department' => 'Administratíva', 'attendance_cislo' => '181']);

        $row = masterPreview(masterCsv("181;Kováčiková Mariana;11\n", 'Vema č.;Name;Workplace No.'))['rows'][0];
        expect($row['osobne_cislo'])->toBe('181')->and($row['data']['stredisko_cislo'])->toBe('11');
    });

    it('rejects a bad attendance number, a bad cost centre and a name it cannot split', function () {
        $preview = masterPreview(masterCsv("1;181;Kováčiková Mariana;1A;Adm;181/1;;\n2;182;Novák;11;Adm;;;\n"));
        expect($preview['errors'][0]['messages'])->toContain('Číslo strediska môže obsahovať len číslice.', 'Číslo v dochádzke musí byť číslo, napr. 181 alebo 181-1.')
            ->and($preview['errors'][1]['messages'][0])->toContain('„Novák“ sa nedá rozdeliť');
    });

    it('keeps separate priezvisko/meno columns when present', function () {
        $path = masterCsv("181;Iné Meno;Kováčiková;Mariana\n", 'Vema;Name;priezvisko;meno');
        expect(masterPreview($path)['rows'][0]['data'])->toMatchArray(['last_name' => 'Kováčiková', 'first_name' => 'Mariana']);
    });
});

describe('re-import of people from the old format', function () {
    it('updates the same people by osobné číslo = Vema, never duplicates', function () {
        masterGroups();
        $old = "osobne_cislo;priezvisko;meno;stredisko;vema_cislo;cislo_dochadzka\n181;Kováčiková;Mariana;Adm;181;\n312;Porubský;Roman;Adm;312;274\n24;Hanzen;Erika;Beauty;24;\n";
        $path = tempnam(sys_get_temp_dir(), 'old');
        file_put_contents($path, $old);
        app(EmployeeImport::class)->apply(app(EmployeeImport::class)->preview($path, 'old.csv'), by: $this->admin);
        $ids = Employee::query()->orderBy('osobne_cislo')->pluck('id', 'osobne_cislo')->all();

        $preview = masterPreview(masterCsv(ROMAN_SHEET));
        $byNumber = collect($preview['rows'])->keyBy('osobne_cislo');
        expect($byNumber['181']['action'])->toBe('update')
            ->and($byNumber['24']['changes']['last_name'])->toBe(['old' => 'Hanzen', 'new' => 'Filip Hanzen'])
            ->and($byNumber['312']['changes'])->not->toHaveKey('attendance_cislo') // 274 already
            ->and(collect($preview['rows'])->where('action', 'create')->pluck('osobne_cislo')->values()->all())->toBe(['115', '285']);

        app(EmployeeImport::class)->apply($preview, by: $this->admin);
        expect(Employee::query()->count())->toBe(5)
            ->and(Employee::query()->whereIn('osobne_cislo', ['181', '312', '24'])->pluck('id', 'osobne_cislo')->sortKeys()->all())->toBe(collect($ids)->sortKeys()->all())
            ->and(Employee::query()->where('osobne_cislo', '24')->sole()->last_name)->toBe('Filip Hanzen')
            ->and(Employee::query()->where('osobne_cislo', '181')->sole()->stredisko_cislo)->toBe('11');

        // Re-import the same sheet again: nothing new, nothing changed.
        $again = masterPreview(masterCsv(ROMAN_SHEET));
        expect(collect($again['rows'])->pluck('action')->unique()->values()->all())->toBe(['unchanged']);
    });

    it('matches "0181" by the Vema number and keeps the existing login number', function () {
        $old = employee(['osobne_cislo' => '0181', 'last_name' => 'Kováčiková', 'first_name' => 'Mariana']);
        $preview = masterPreview(masterCsv("1;181;Kováčiková Mariana;11;Adm;181-1;;\n"));

        expect($preview['rows'][0])->toMatchArray(['osobne_cislo' => '0181', 'action' => 'update'])
            ->and($preview['warnings'][0]['message'])->toContain('spárované')->toContain('0181');
        app(EmployeeImport::class)->apply($preview, by: $this->admin);
        expect(Employee::query()->count())->toBe(1)
            ->and($old->fresh()->only(['osobne_cislo', 'vema_cislo', 'attendance_cislo']))->toBe(['osobne_cislo' => '0181', 'vema_cislo' => '181', 'attendance_cislo' => '181']);
    });
});

describe('Forma stravy → group', function () {
    it('matches the group name or its "Názvy v importe", ignoring case and diacritics', function () {
        [$lunch, , $tickets] = masterGroups();
        $preview = masterPreview(masterCsv("1;1;Nový Adam;;;;;POVINNA - LEN OBED\n2;2;Nová Eva;;;;;lístky\n3;3;Iný Ivan;;;;;Povinná – len obed\n"));
        expect($preview['errors'])->toBe([])
            ->and(collect($preview['rows'])->pluck('group.id')->all())->toBe([$lunch->id, $tickets->id, $lunch->id]);
    });

    it('reports an unknown meal form as a row error naming the value', function () {
        masterGroups();
        $preview = masterPreview(masterCsv("1;1;Nový Adam;;;;;Vegán\n"));
        expect($preview['rows'])->toBe([])
            ->and($preview['errors'][0]['messages'][0])->toContain('„Vegán“')->toContain('Názvy v importe');
    });

    it('rejects an import name already used by another group', function () {
        masterGroups();
        Livewire::test(ManageMealGroups::class)
            ->callAction('create', ['name' => 'Nová', 'import_aliases' => ['objednava si'], 'default_tickets' => 0, 'mandatory_per_day' => 2, 'estimate_source' => 'plan', 'settlement' => 'payroll', 'is_active' => true, 'initial_mode' => 'daily'])
            ->assertHasActionErrors(['import_aliases']);
        Livewire::test(ManageMealGroups::class)
            ->callAction('create', ['name' => 'Nová', 'import_aliases' => ['Brigádnici'], 'default_tickets' => 0, 'mandatory_per_day' => 2, 'estimate_source' => 'plan', 'settlement' => 'payroll', 'is_active' => true, 'initial_mode' => 'daily'])
            ->assertHasNoActionErrors();
        expect(MealGroup::query()->where('name', 'Nová')->sole()->import_aliases)->toBe(['Brigádnici']);
    });

    it('never changes an existing group for an empty cell and does not list that person as unset', function () {
        [, , $tickets] = masterGroups();
        joinGroup(employee(['osobne_cislo' => '115', 'last_name' => 'Sokolová', 'first_name' => 'Lenka', 'vema_cislo' => '115', 'attendance_cislo' => '115']), $tickets, '2026-10-01');
        $preview = masterPreview(masterCsv("1;115;Sokolová Lenka;;;115-1;;\n2;900;Nový Adam;;;;;\n"));

        expect($preview['rows'][0]['group'])->toBeNull()
            ->and($preview['rows'][0]['action'])->toBe('unchanged')
            ->and(collect($preview['unset'])->pluck('osobne_cislo')->all())->toBe(['900'])
            ->and($preview['group_counts'])->toBe(['Lístky' => 1]);
    });
});

describe('warnings', function () {
    it('warns when the name in attendance does not fit (wrong Dochádzka number)', function () {
        $preview = masterPreview(masterCsv("1;181;Kováčiková Mariana;;;115-1;Sokolová L.;\n2;182;Porubský Roman;;;274-1;Porubský R.;\n"));
        $warnings = collect($preview['warnings']);
        expect($warnings->where('line', 2)->pluck('message')->join(' '))->toContain('Meno v dochádzke „Sokolová L.“ nesedí')
            ->and($warnings->where('line', 3))->toBeEmpty();
    });

    it('warns about ticket/mandatory people without an attendance number, not about daily or fixed ones', function () {
        masterGroups();
        $fixed = MealGroup::query()->create(['name' => 'SZČO', 'import_aliases' => ['Živnostník'], 'default_tickets' => 0, 'is_active' => true]);
        MealGroupMode::query()->create(['meal_group_id' => $fixed->id, 'mode' => PricingMode::Fixed, 'effective_from' => '2026-10-01']);
        $preview = masterPreview(masterCsv("1;1;Prvý Adam;;;;;Povinná - len obed\n2;2;Druhý Boris;;;;;Objednáva si\n3;3;Tretí Cyril;;;;;\n4;4;Štvrtý Dano;;;;;Živnostník\n5;5;Piaty Emil;;;5-1;;Objednáva si\n"));

        $lines = collect($preview['warnings'])->filter(fn ($w) => str_contains($w['message'], 'chýba číslo v dochádzke'))->pluck('line')->all();
        expect($lines)->toBe([2, 3]);
    });
});

describe('group start date', function () {
    it('no group → from today; a change to another group → from the 1st of next month', function () {
        [$lunch, $both] = masterGroups();
        $changer = joinGroup(employee(['osobne_cislo' => '312', 'attendance_cislo' => '274']), $lunch, '2026-10-01');
        $plain = employee(['osobne_cislo' => '181']);
        $preview = masterPreview(masterCsv("1;312;Porubský Roman;;;274-1;;Povinná - obed+večera\n2;181;Kováčiková Mariana;;;181-1;;Povinná - len obed\n3;900;Nový Adam;;;900-1;;Povinná - len obed\n"));

        expect(collect($preview['rows'])->map(fn ($r) => [$r['group']['from'], $r['group']['rule']])->all())
            ->toBe([['2026-12-01', 'change'], ['2026-11-24', 'first'], ['2026-11-24', 'first']]);

        app(EmployeeImport::class)->apply($preview, by: $this->admin);
        expect(EmployeeCalendar::for($changer)->groupOn('2026-11-30')?->id)->toBe($lunch->id)
            ->and(EmployeeCalendar::for($changer)->groupOn('2026-12-01')?->id)->toBe($both->id)
            ->and(EmployeeCalendar::for($plain)->groupOn('2026-11-24')?->id)->toBe($lunch->id);
    });

    it('can override the date for all group changes in the preview, new employees included', function () {
        [$lunch, $both] = masterGroups();
        $changer = joinGroup(employee(['osobne_cislo' => '312', 'attendance_cislo' => '274']), $lunch, '2026-10-01');

        Livewire::test(ImportEmployees::class)
            ->set('file', UploadedFile::fake()->createWithContent('z.csv', "Vema;Name;Dochádzka;Forma stravy\n312;Porubský Roman;274-1;Povinná - obed+večera\n900;Nový Adam;900-1;Povinná - len obed\n"))
            ->call('analyze')
            ->assertSee('Osobné číslo = Vema číslo')
            ->assertSee('od 1. 12. 2026')
            ->set('groupFrom', '2026-11-26')
            ->assertSee('od 26. 11. 2026')
            ->call('saveImport')
            ->assertHasNoErrors();

        $new = Employee::query()->where('osobne_cislo', '900')->sole();
        expect(EmployeeCalendar::for($changer)->groupOn('2026-11-25')?->id)->toBe($lunch->id)
            ->and(EmployeeCalendar::for($changer)->groupOn('2026-11-26')?->id)->toBe($both->id)
            ->and(EmployeeCalendar::for($new)->groupOn('2026-11-25'))->toBeNull()
            ->and(EmployeeCalendar::for($new)->groupOn('2026-11-26')?->id)->toBe($lunch->id);
    });

    it('refuses an override date in a closed month', function () {
        masterGroups();
        app(ClosedMonths::class)->close('2026-10', $this->admin);

        Livewire::test(ImportEmployees::class)
            ->set('file', UploadedFile::fake()->createWithContent('z.csv', "Vema;Name;Forma stravy\n900;Nový Adam;Objednáva si\n"))
            ->call('analyze')
            ->set('groupFrom', '2026-10-15')
            ->call('saveImport')
            ->assertHasErrors('groupFrom');
        expect(Employee::query()->where('osobne_cislo', '900')->exists())->toBeFalse();
    });
});

describe('employee list', function () {
    it('filters "Bez skupiny" and assigns a group to many people at once', function () {
        [$lunch, $both] = masterGroups();
        $a = employee(['last_name' => 'Bezskupiny']);
        $b = employee(['last_name' => 'Tiežbez']);
        $in = joinGroup(employee(['last_name' => 'Vskupine']), $lunch, '2026-10-01');
        $planned = joinGroup(employee(['last_name' => 'Odbudúca']), $lunch, '2026-12-01');

        Livewire::test(ListEmployees::class)
            ->filterTable('without_group')
            ->assertCanSeeTableRecords([$a, $b])
            ->assertCanNotSeeTableRecords([$in, $planned])
            ->callTableBulkAction('assignGroup', [$a, $b], ['meal_group_id' => $both->id])
            ->assertHasNoTableBulkActionErrors();

        expect(EmployeeCalendar::for($a)->groupOn('2026-11-24')?->id)->toBe($both->id)
            ->and(EmployeeCalendar::for($b)->groupOn('2026-11-23'))->toBeNull()
            ->and(EmployeeCalendar::for($b)->groupOn('2026-11-24')?->id)->toBe($both->id);
    });

    it('bulk assign: empty date = next month for a group change; an explicit date applies to everyone', function () {
        [$lunch, $both] = masterGroups();
        $in = joinGroup(employee(['last_name' => 'Vskupine']), $lunch, '2026-10-01');
        $none = employee(['last_name' => 'Bez']);

        Livewire::test(ListEmployees::class)
            ->callTableBulkAction('assignGroup', [$in, $none], ['meal_group_id' => $both->id, 'from' => null])
            ->assertHasNoTableBulkActionErrors();
        expect(EmployeeCalendar::for($in)->groupOn('2026-11-30')?->id)->toBe($lunch->id)
            ->and(EmployeeCalendar::for($in)->groupOn('2026-12-01')?->id)->toBe($both->id)
            ->and(EmployeeCalendar::for($none)->groupOn('2026-11-24')?->id)->toBe($both->id);

        Livewire::test(ListEmployees::class)
            ->callTableBulkAction('assignGroup', [$in], ['meal_group_id' => $lunch->id, 'from' => '2026-11-25'])
            ->assertHasNoTableBulkActionErrors();
        expect(EmployeeCalendar::for($in)->groupOn('2026-12-01')?->id)->toBe($lunch->id); // planned change replaced
    });

    it('pre-fills today when nobody selected has a group yet, else leaves the automatic rule', function () {
        [$lunch] = masterGroups();
        $a = employee();
        Livewire::test(ListEmployees::class)
            ->mountTableBulkAction('assignGroup', [$a])
            ->assertTableBulkActionDataSet(['from' => '2026-11-24']);
        $in = joinGroup(employee(), $lunch, '2026-10-01');
        Livewire::test(ListEmployees::class)
            ->mountTableBulkAction('assignGroup', [$a, $in])
            ->assertTableBulkActionDataSet(['from' => null]);
    });

    it('shows and filters the cost-centre number', function () {
        $a = employee(['stredisko_cislo' => '11', 'department' => 'Administratíva']);
        $b = employee(['stredisko_cislo' => '41']);
        Livewire::test(ListEmployees::class)
            ->assertSee('č. 11')
            ->filterTable('stredisko_cislo', '11')
            ->assertCanSeeTableRecords([$a])
            ->assertCanNotSeeTableRecords([$b]);
    });
});

describe('dashboard widget', function () {
    it('counts active employees without a meal form and is hidden at zero', function () {
        $group = ticketGroup(20, '2026-10-01');
        joinGroup(employee(), $group, '2026-10-01');
        expect(EmployeesWithoutGroup::canView())->toBeFalse();

        employee();
        employee();
        employee(['is_active' => false]);
        joinGroup(employee(), $group, '2026-12-01'); // planned counts as set up
        joinGroup(joinGroup(employee(), $group, '2026-10-01'), null, '2026-11-01'); // removed from the group

        expect(EmployeesWithoutGroup::count())->toBe(3)
            ->and(EmployeesWithoutGroup::canView())->toBeTrue();
        $this->get('/admin')->assertOk();
        Livewire::test(EmployeesWithoutGroup::class)->assertSee('3 aktívni zamestnanci bez formy stravy');
        $this->get(EmployeesWithoutGroup::listUrl())->assertOk();
    });

    it('is only for admins', function () {
        employee();
        $this->actingAs(User::factory()->withRoles(Role::Kitchen)->create());
        expect(EmployeesWithoutGroup::canView())->toBeFalse();
    });
});

describe('cost centre in exports', function () {
    it('adds stredisko_cislo to the order list, Súhrn, Mesiac and the Vema file', function () {
        $e = employee(['last_name' => 'Kováčiková', 'first_name' => 'Mariana', 'stredisko_cislo' => '11', 'vema_cislo' => '181']);
        $items = menuItems('2026-11-25', lunch());
        app(OrderService::class)->place($e, $items[0], Actor::employee($e));

        $csv = file(app(OrderListExport::class)->csv(CarbonImmutable::parse('2026-11-25'), CarbonImmutable::parse('2026-11-25')), FILE_IGNORE_NEW_LINES);
        $header = str_getcsv(ltrim($csv[0], "\xEF\xBB\xBF"), ';', '"', '');
        $row = str_getcsv($csv[1], ';', '"', '');
        expect($row[array_search('stredisko_cislo', $header, true)])->toBe('11');

        $sheets = masterXlsx(app(MonthlyMatrixXlsx::class)->write(MonthlyData::for('2026-11')));
        $col = array_search('Číslo strediska', $sheets['Súhrn'][0], true);
        expect($col)->not->toBeFalse()
            ->and($sheets['Súhrn'][1][$col])->toBe('11')
            ->and(collect($sheets['Mesiac'])->flatten()->filter(fn ($v) => is_string($v) && str_contains($v, 'stredisko č. 11')))->not->toBeEmpty();

        expect(VemaFormat::columns())->toHaveKey('stredisko_cislo');
    });
});
