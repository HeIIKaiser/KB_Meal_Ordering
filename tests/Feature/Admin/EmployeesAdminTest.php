<?php

use App\Domain\Employees\EmployeeImport;
use App\Domain\Employees\PinIssuer;
use App\Enums\Role;
use App\Filament\Pages\ImportEmployees;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Hashing\HashManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

function csvFile(string $content, string $name = 'import.csv'): string
{
    $path = tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($path, $content);

    return $path;
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

describe('authorization', function () {
    it('lets only admins manage employees', function (Role $role) {
        $this->actingAs(User::factory()->withRoles($role)->create())
            ->get('/admin/employees')->assertForbidden();
        $this->actingAs(User::factory()->withRoles($role)->create())
            ->get('/admin/employees/import')->assertForbidden();
    })->with([Role::Kitchen, Role::Payroll]);

    it('shows employees to admins', function () {
        $e = employee(['last_name' => 'Ďuricová', 'first_name' => 'Žaneta']);
        $this->actingAs($this->admin)->get('/admin/employees')->assertOk()->assertSee('Ďuricová Žaneta');
    });
});

it('creates and edits an employee', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateEmployee::class)
        ->fillForm(['osobne_cislo' => '5555', 'last_name' => 'Šťastný', 'first_name' => 'Ľubo', 'department' => 'Údržba', 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    $e = Employee::query()->where('osobne_cislo', '5555')->sole();

    Livewire::test(CreateEmployee::class)
        ->fillForm(['osobne_cislo' => '5555', 'last_name' => 'X', 'first_name' => 'Y'])
        ->call('create')
        ->assertHasFormErrors(['osobne_cislo' => 'unique']);

    Livewire::test(EditEmployee::class, ['record' => $e->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($e->fresh()->is_active)->toBeFalse()
        ->and(DB::table('activity_log')->where('log_name', 'employees')->where('subject_id', $e->id)->count())->toBe(2);
});

it('cannot delete employees', function () {
    $this->actingAs($this->admin);
    expect($this->admin->can('delete', employee()))->toBeFalse();
});

describe('logins and access slips', function () {
    it('generates non-trivial 6-digit PINs', function () {
        foreach (range(1, 200) as $i) {
            $pin = PinIssuer::generate();
            expect($pin)->toMatch('/^\d{6}$/')->not->toBe('123456')->not->toBe('000000');
        }
    });

    it('gives every new employee a working, printable login automatically', function () {
        $this->actingAs($this->admin);

        Livewire::test(CreateEmployee::class)
            ->fillForm(['osobne_cislo' => '6001', 'last_name' => 'Nová', 'first_name' => 'Ema', 'is_active' => true])
            ->call('create')
            ->assertNotified('Zamestnanec vytvorený – PIN bol vygenerovaný');

        $e = Employee::query()->where('osobne_cislo', '6001')->sole();
        expect($e->printablePin())->toMatch('/^\d{6}$/')
            ->and($e->checkPin($e->printablePin()))->toBeTrue()
            ->and($e->pin_set_by)->toBe(Employee::PIN_BY_SYSTEM)
            ->and($e->pinStatus())->toBe('na lístku');

        // the employee can log in with it
        auth()->logout();
        $this->post('/prihlasenie', ['osobne_cislo' => '6001', 'pin' => $e->printablePin()])->assertRedirect(route('home'));
    });

    it('stores the printable PIN encrypted, never in plain text', function () {
        $e = employee(['osobne_cislo' => '6002']);
        $e->setPin('482913');
        $e->save();

        $raw = DB::table('employees')->where('id', $e->id)->value('pin_plain');
        expect($raw)->not->toContain('482913')
            ->and(decrypt($raw, false))->toBe('482913')
            ->and($e->fresh()->toArray())->not->toHaveKey('pin_plain');
    });

    it('prints the CURRENT PIN individually and in batch without changing it', function () {
        $this->actingAs($this->admin);
        $a = employee(['first_name' => 'Ján', 'last_name' => 'Novák', 'osobne_cislo' => '7001']);
        $b = employee(['first_name' => 'Eva', 'last_name' => 'Adamová', 'osobne_cislo' => '7002']);
        $hash = $a->pin_hash;

        Livewire::test(ListEmployees::class)
            ->callTableBulkAction('printSlips', [$a, $b])
            ->assertRedirect(route('admin.slips', ['zamestnanci' => $b->id.','.$a->id])); // table order: Adamová, Novák

        $this->get(route('admin.slips', ['zamestnanci' => $a->id.','.$b->id]))
            ->assertOk()
            ->assertSeeInOrder(['Eva Adamová', 'Ján Novák'])  // alphabetical
            ->assertSee('1234')
            ->assertSee('data:image/svg+xml;base64,', false)
            ->assertHeader('Cache-Control', 'no-store, private');

        // printing again shows the same PIN; nothing changed, nobody logged out
        $this->get(route('admin.slips', ['zamestnanci' => $a->id]))->assertSee('Ján Novák')->assertSee('1234');
        expect($a->fresh()->pin_hash)->toBe($hash)
            ->and($a->fresh()->slip_printed_at)->not->toBeNull()
            ->and(DB::table('activity_log')->where('event', 'slips_printed')->count())->toBe(2);

        // single slip action links to the same page
        Livewire::test(ListEmployees::class)->assertTableActionHasUrl('printSlip', route('admin.slips', ['zamestnanci' => $a->id]), $a);
    });

    it('does not print a PIN the employee chose themselves', function () {
        $this->actingAs($this->admin);
        $e = employee(['first_name' => 'Igor', 'last_name' => 'Tichý']);
        $e->setPin('905172', Employee::PIN_BY_EMPLOYEE);
        $e->save();

        expect($e->fresh()->printablePin())->toBeNull()->and($e->fresh()->pinStatus())->toBe('zmenil zamestnanec');

        $this->get(route('admin.slips', ['zamestnanci' => $e->id]))
            ->assertOk()->assertDontSee('905172')->assertSee('PIN si zmenil(a) sám/sama');
    });

    it('marks employees whose self-service PIN change cleared the printable PIN', function () {
        $e = employee(['osobne_cislo' => '6003']);
        $e->setPin('1234');
        $e->save();
        $this->actingAs($e->fresh(), 'employee')
            ->put('/ucet/pin', ['current_pin' => '1234', 'pin' => '8642', 'pin_confirmation' => '8642'])
            ->assertRedirect(route('account'));

        expect($e->fresh()->pin_plain)->toBeNull()->and($e->fresh()->pin_set_by)->toBe(Employee::PIN_BY_EMPLOYEE);
    });

    it('resets PINs (new PIN, logs out other devices) and opens their slips', function () {
        $this->actingAs($this->admin);
        $a = employee(['osobne_cislo' => '7101']);
        $b = employee(['osobne_cislo' => '7102']);
        $old = $a->pin_hash;

        Livewire::test(ListEmployees::class)
            ->callTableBulkAction('slips', [$a, $b])
            ->assertRedirectContains('/admin/listky?zamestnanci=');

        $a->refresh();
        expect($a->pin_hash)->not->toBe($old)
            ->and($a->printablePin())->not->toBe('1234')
            ->and($a->checkPin($a->printablePin()))->toBeTrue()
            ->and($a->pin_set_by)->toBe(Employee::PIN_BY_ADMIN)
            ->and(DB::table('activity_log')->where('event', 'pin_reset')->count())->toBe(2);

        $this->get(route('admin.slips', ['zamestnanci' => $a->id]))->assertSee($a->printablePin());

        Livewire::test(EditEmployee::class, ['record' => $b->getRouteKey()])
            ->callAction('resetPin')
            ->assertRedirect(route('admin.slips', ['zamestnanci' => $b->id]));
    });

    it('offers to print all slips not printed yet', function () {
        $this->actingAs($this->admin);
        $printed = employee(['slip_printed_at' => now()]);
        $new1 = employee();
        $new2 = employee();
        employee(['is_active' => false]);

        Livewire::test(ListEmployees::class)
            ->assertActionVisible('printNew')
            ->assertActionHasLabel('printNew', 'Nevytlačené lístky (2)')
            ->assertActionHasUrl('printNew', route('admin.slips', ['zamestnanci' => $new1->id.','.$new2->id]))
            ->filterTable('not_printed')
            ->assertCanSeeTableRecords([$new1, $new2])
            ->assertCanNotSeeTableRecords([$printed]);

        $this->get(route('admin.slips', ['zamestnanci' => $new1->id.','.$new2->id]));
        Livewire::test(ListEmployees::class)->assertActionHidden('printNew');
    });

    it('refuses the slips page to non-admins and guests and ignores junk ids', function () {
        $e = employee();
        $this->get('/admin/listky?zamestnanci='.$e->id)->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->kitchen()->create())->get('/admin/listky?zamestnanci='.$e->id)->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/listky?zamestnanci=abc,,-1,999999')->assertOk()->assertSee('Nie sú vybraní žiadni zamestnanci');
    });
});

describe('import', function () {
    it('previews and applies an upsert by osobné číslo (CSV ; Windows-1250)', function () {
        $existing = employee(['osobne_cislo' => '100', 'last_name' => 'Stará', 'first_name' => 'Eva', 'department' => 'Recepcia']);
        $untouched = employee(['osobne_cislo' => '101', 'last_name' => 'Rovnaký', 'first_name' => 'Ján', 'department' => null]);
        $missing = employee(['osobne_cislo' => '999', 'last_name' => 'Chýbajúci', 'first_name' => 'Ivan']);

        $csv = "Osobné číslo;Priezvisko;Meno;Stredisko;Aktívny\n"
            ."100;Nová;Eva;Wellness;áno\n"
            ."101;Rovnaký;Ján;;\n"
            ."200;Šimková;Žofia;Kuchyňa;\n"
            .";Bez;Čísla;;\n"
            ."300;;Len meno;;\n"
            ."200;Duplicitná;X;;\n";
        $path = csvFile(iconv('UTF-8', 'CP1250', $csv));

        $preview = app(EmployeeImport::class)->preview($path, 'x.csv');

        expect(collect($preview['rows'])->pluck('action', 'osobne_cislo')->all())->toBe(['100' => 'update', '101' => 'unchanged', '200' => 'create'])
            ->and(collect($preview['errors'])->pluck('line')->all())->toBe([5, 6, 7])
            ->and(collect($preview['rows'])->firstWhere('osobne_cislo', '100')['changes'])->toHaveKeys(['last_name', 'department'])
            ->and(collect($preview['missing'])->pluck('osobne_cislo')->all())->toBe(['999']);

        $result = app(EmployeeImport::class)->apply($preview, deactivateMissing: true);

        $new = Employee::where('osobne_cislo', '200')->sole();
        expect($result)->toBe(['created' => 1, 'updated' => 1, 'deactivated' => 1, 'created_ids' => [$new->id]])
            ->and($new->printablePin())->toMatch('/^\d{6}$/') // imported employees get a login right away
            ->and($existing->fresh()->last_name)->toBe('Nová')
            ->and($existing->fresh()->department)->toBe('Wellness')
            ->and(Employee::where('osobne_cislo', '200')->sole()->first_name)->toBe('Žofia')
            ->and($missing->fresh()->is_active)->toBeFalse()
            ->and(Employee::count())->toBe(4);
    });

    it('reads XLSX files', function () {
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['osobne_cislo', 'priezvisko', 'meno', 'stredisko']));
        $writer->addRow(Row::fromValues([4242, 'Ťapák', 'Ľudovít', 'Údržba']));
        $writer->close();

        $preview = app(EmployeeImport::class)->preview($path);
        expect($preview['rows'][0])->toMatchArray(['osobne_cislo' => '4242', 'action' => 'create', 'name' => 'Ťapák Ľudovít']);
    });

    it('runs through the import page', function () {
        $this->actingAs($this->admin);

        Livewire::test(ImportEmployees::class)
            ->set('file', UploadedFile::fake()->createWithContent('z.csv', "osobne_cislo,priezvisko,meno\n321,Kráľ,Peter\n"))
            ->call('analyze')
            ->assertHasNoErrors()
            ->assertSee('Kráľ Peter')
            ->call('saveImport')
            ->assertRedirect('/admin/employees')
            ->assertNotified('Import dokončený');

        $e = Employee::where('osobne_cislo', '321')->sole();
        expect($e->pinStatus())->toBe('na lístku');
    });

    it('rejects unsupported files', function () {
        $this->actingAs($this->admin);

        Livewire::test(ImportEmployees::class)
            ->set('file', UploadedFile::fake()->create('z.pdf', 10, 'application/pdf'))
            ->call('analyze')
            ->assertHasErrors('file');
    });

    it('serves the CSV template', function () {
        $this->actingAs($this->admin)->get('/admin/sablona/zamestnanci')
            ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertSee('osobne_cislo;priezvisko', false);
    });
});

describe('fast imports (PIN hash computed at first login)', function () {
    it('does not hash app-generated PINs up front, and hashes them on the first login', function () {
        $e = Employee::query()->create(['osobne_cislo' => '8001', 'last_name' => 'Rýchla', 'first_name' => 'Eva']);
        $pin = $e->printablePin();

        expect($e->pin_hash)->toBeNull()
            ->and($e->pinStatus())->toBe('na lístku')
            ->and($e->checkPin($pin))->toBeTrue()
            ->and($e->checkPin('000000'))->toBeFalse();

        $this->withoutVite();
        $this->post('/prihlasenie', ['osobne_cislo' => '8001', 'pin' => '000000'])->assertSessionHasErrors('osobne_cislo');
        $this->post('/prihlasenie', ['osobne_cislo' => '8001', 'pin' => $pin])->assertRedirect(route('home'));

        $e->refresh();
        expect($e->pin_hash)->not->toBeNull()
            ->and(Hash::check($pin, $e->pin_hash))->toBeTrue()
            ->and($e->printablePin())->toBe($pin); // still printable
    });

    it('hashes a PIN chosen by the employee immediately and never stores it readable', function () {
        $e = employee();
        $e->setPin('5577', Employee::PIN_BY_EMPLOYEE);
        $e->save();

        expect($e->pin_hash)->not->toBeNull()->and($e->pin_plain)->toBeNull()->and($e->checkPin('5577'))->toBeTrue();
    });

    it('imports and batch-resets many employees without computing hashes', function () {
        $csv = "osobne_cislo;priezvisko;meno\n".collect(range(1, 300))->map(fn ($i) => (9000 + $i).";Priezvisko$i;Meno")->join("\n");
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, $csv);

        Hash::swap(new class(app()) extends HashManager
        {
            public static int $calls = 0;

            public function make($value, array $options = [])
            {
                self::$calls++;

                return parent::make($value, $options);
            }
        });

        $import = app(EmployeeImport::class);
        $import->apply($import->preview($path, 'x.csv'));
        app(PinIssuer::class)->issue(Employee::query()->get(), $this->admin);

        expect(Employee::count())->toBe(300)
            ->and(Hash::getFacadeRoot()::$calls)->toBe(0);
    });
});
