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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

describe('PIN reset and access slips', function () {
    it('generates non-trivial 6-digit PINs', function () {
        foreach (range(1, 200) as $i) {
            $pin = PinIssuer::generate();
            expect($pin)->toMatch('/^\d{6}$/')->not->toBe('123456')->not->toBe('000000');
        }
    });

    it('resets PINs in bulk and shows them exactly once on the slips page', function () {
        $this->actingAs($this->admin);
        $a = employee(['first_name' => 'Ján', 'last_name' => 'Novák', 'osobne_cislo' => '7001']);
        $b = employee(['osobne_cislo' => '7002']);
        $oldHash = $a->pin_hash;

        Livewire::test(ListEmployees::class)
            ->callTableBulkAction('slips', [$a, $b])
            ->assertRedirect(route('admin.slips'));

        $slips = collect(session('access_slips'))->keyBy('employee_id');
        expect($slips)->toHaveCount(2)
            ->and($a->fresh()->pin_hash)->not->toBe($oldHash)
            ->and($a->fresh()->checkPin($slips[$a->id]['pin']))->toBeTrue();
        $slips = [$slips[$a->id]];

        $this->get('/admin/listky')
            ->assertOk()
            ->assertSee('Ján Novák')
            ->assertSee($slips[0]['pin'])
            ->assertSee('data:image/svg+xml;base64,', false)
            ->assertHeader('Cache-Control', 'no-store, private');

        // second visit: PINs are gone
        $this->get('/admin/listky')->assertOk()->assertDontSee($slips[0]['pin'])->assertSee('iba raz');

        expect(DB::table('activity_log')->where('event', 'pin_reset')->count())->toBe(2);
    });

    it('resets a single PIN from the edit page', function () {
        $this->actingAs($this->admin);
        $e = employee();

        Livewire::test(EditEmployee::class, ['record' => $e->getRouteKey()])
            ->callAction('resetPin')
            ->assertRedirect(route('admin.slips'));
        expect(session('access_slips'))->toHaveCount(1);
    });

    it('refuses the slips page to non-admins and guests', function () {
        $this->get('/admin/listky')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->kitchen()->create())->get('/admin/listky')->assertForbidden();
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

        expect($result)->toBe(['created' => 1, 'updated' => 1, 'deactivated' => 1])
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
            ->call('commit')
            ->assertRedirect('/admin/employees');

        expect(Employee::where('osobne_cislo', '321')->exists())->toBeTrue();
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
