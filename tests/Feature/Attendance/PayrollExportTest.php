<?php

use App\Domain\Exports\MonthlyData;
use App\Domain\Exports\MonthlyMatrixXlsx;
use App\Domain\Exports\OrderListExport;
use App\Domain\Exports\Payroll\PayrollData;
use App\Domain\Exports\Payroll\PayrollFormats;
use App\Domain\Exports\Payroll\VemaFormat;
use App\Domain\Tickets\ClosedMonths;
use App\Enums\Role;
use App\Models\User;
use App\Support\LocalTime;
use OpenSpout\Reader\XLSX\Reader;

function readSheets(string $path): array
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

beforeEach(function () {
    $this->withoutVite();
    travelToLocal('2026-10-12 07:00:00');
    $this->cook = joinGroup(employee(['osobne_cislo' => '0029', 'vema_cislo' => '029', 'last_name' => 'Kuchárová', 'first_name' => 'Eva']), mandatoryGroup(2, '2026-10-01'), '2026-10-01');
    $this->daily = employee(['osobne_cislo' => '0031', 'vema_cislo' => '31', 'last_name' => 'Denný', 'first_name' => 'Ján']);
    $items = menuItems('2026-10-12', lunch(), 2);
    orderAll($this->cook, [$items[0]]);                  // take-home: 6,00
    orderAll($this->daily, [$items[0], $items[1]]);      // 1,50 + 6,00
    syncAttendance('2026-10', ['29' => ['2026-10-12' => ['12:00', '12:00'], '2026-10-13' => ['12:00', '12:00']]]); // 2 days × 2
    travelToLocal('2026-11-12 08:00:00');
    $this->admin = adminUser();
});

it('builds the provisional Vema file: numbers without leading zeros, totals equal to the matrix', function () {
    $data = PayrollData::for('2026-10');
    $rows = readSheets(app(VemaFormat::class)->write($data))['Vema 2026-10'];

    expect($rows[0])->toBe(['vema_cislo', 'priezvisko', 'meno', 'zamestnanecka_ks', 'dokupovana_ks', 'povinna_ks', 'suma_eur'])
        ->and($rows[1])->toBe([29, 'Kuchárová', 'Eva', 0, 1, 4, 12])     // 6,00 + 4 × 1,50
        ->and($rows[2])->toBe([31, 'Denný', 'Ján', 1, 1, 0, 7.5]);

    $summary = readSheets(app(MonthlyMatrixXlsx::class)->write(MonthlyData::for('2026-10')))['Súhrn'];
    $sumIndex = array_search('Suma €', $summary[0], true);
    $matrixTotal = collect($summary)->first(fn ($r) => $r[0] === 'Spolu')[$sumIndex];
    expect($data->totalCents() / 100)->toBe((float) $matrixTotal)->toBe(19.5);
});

it('is one of the pluggable formats and marked provisional', function () {
    expect(PayrollFormats::all())->toHaveKey('vema')
        ->and(PayrollFormats::get('vema')->label())->toBe('Formát Vema – predbežný')
        ->and(PayrollFormats::get('vema')->isProvisional())->toBeTrue();
});

it('blocks the export when someone with an amount has no Vema number', function () {
    $this->daily->update(['vema_cislo' => null]);
    $this->actingAs($this->admin);

    $this->get(route('exports.payroll', ['mesiac' => '2026-10', 'format' => 'vema']))
        ->assertRedirect()
        ->assertSessionHas('payroll_errors', ['Denný Ján (osobné č. 0031) nemá Vema číslo.']);
    $this->get('/admin/exporty')->assertSee('Export sa nedá vytvoriť')->assertSee('nemá Vema číslo');
});

it('asks to confirm the estimate for an open month, downloads a closed one directly', function () {
    $this->actingAs(User::factory()->withRoles(Role::Payroll)->create());

    $this->get(route('exports.payroll', ['mesiac' => '2026-10', 'format' => 'vema']))->assertSessionHas('payroll_estimate');
    $this->get('/admin/exporty')->assertSee('sumy sú <strong>odhad</strong>', false)->assertSee('Stiahnuť aj tak (odhad)')->assertSee('Formát Vema – predbežný');

    $this->get(route('exports.payroll', ['mesiac' => '2026-10', 'format' => 'vema', 'odhad' => 1]))
        ->assertOk()->assertDownload('vema_zrazky_2026-10.xlsx');

    app(ClosedMonths::class)->close('2026-10', $this->admin);
    $this->get(route('exports.payroll', ['mesiac' => '2026-10', 'format' => 'vema']))->assertOk()->assertDownload();
});

it('is not available to the kitchen', function () {
    $this->actingAs(User::factory()->kitchen()->create())
        ->get(route('exports.payroll', ['mesiac' => '2026-10', 'format' => 'vema']))->assertForbidden();
});

it('adds mandatory meals to the matrix, the summary and the flat list', function () {
    $sheets = readSheets(app(MonthlyMatrixXlsx::class)->write(MonthlyData::for('2026-10')));
    $m = $sheets['Mesiac'];
    $i = collect($m)->search(fn ($r) => str_starts_with((string) $r[0], 'Kuchárová Eva'));
    $line = collect(array_slice($m, $i, 6))->first(fn ($r) => ($r[1] ?? null) === 'Povinná strava');
    expect(array_slice($line, 2 + 11, 2))->toBe([2, 2])          // 12. and 13. October
        ->and(array_slice($line, -2))->toBe([4, 6]);

    $s = $sheets['Súhrn'];
    $h = $s[0];
    $cook = collect($s)->first(fn ($r) => $r[0] === '0029');
    expect($cook[array_search('Režim', $h)])->toBe('povinna')
        ->and($cook[array_search('Povinná strava ks', $h)])->toBe(4)
        ->and($cook[array_search('Suma €', $h)])->toBe(12)
        ->and($cook[array_search('Vema číslo', $h)])->toBe(29);

    $csv = file_get_contents(app(OrderListExport::class)->csv(LocalTime::date('2026-10-01'), LocalTime::date('2026-10-31')));
    $lines = array_map(fn ($l) => str_getcsv($l, ';', '"', ''), array_filter(explode("\n", trim(substr($csv, 3)))));
    $mandatory = collect($lines)->filter(fn ($l) => ($l[8] ?? null) === 'povinna');
    expect($mandatory)->toHaveCount(4)
        ->and($mandatory->first()[14])->toBe('povinna')
        ->and($mandatory->first()[9])->toBe('1,50')
        ->and(collect($lines)->first(fn ($l) => $l[2] === '0029' && $l[8] === 'dokupovana')[14])->toBe('povinna'); // take-home order in mandatory mode
});
