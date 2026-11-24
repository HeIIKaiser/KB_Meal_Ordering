<?php

use App\Domain\Exports\MonthlyData;
use App\Domain\Exports\MonthlyMatrixXlsx;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\Employee;
use App\Support\NaturalSort;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

/*
 * Numbers stored as text sort numerically: 1, 2, 10, 11, 20, 100 (not 1, 10, 100, 11, 2, 20).
 * Values that are not purely digits come after the numbers, alphabetically; empty last.
 */

const NATURAL_EXPECTED = ['1', '2', '10', '11', '20', '100', '0123', 'A7', 'S001']; // 0123 = 123

beforeEach(function () {
    travelToLocal('2026-11-24 07:00:00');
    $this->actingAs(adminUser());
});

/** Employees with the same name, so only the number decides the order. */
function sameNameEmployees(string $field = 'osobne_cislo'): array
{
    $out = [];
    foreach (['100', 'S001', '2', '20', '0123', '1', '11', 'A7', '10'] as $i => $n) {
        $attrs = ['last_name' => 'Rovnaký', 'first_name' => 'Ján', $field => $n];
        if ($field !== 'osobne_cislo') {
            $attrs['osobne_cislo'] = 'X'.$i; // osobne_cislo is required and unique
        }
        $out[$n] = employee($attrs);
    }

    return $out;
}

it('compares in PHP: numbers by value, then other values alphabetically, empty last', function () {
    expect(collect(['100', 'S001', '2', '', '20', '0123', '1', null, '11', 'A7', '10'])->sortByNatural(fn ($v) => $v)->values()->all())
        ->toBe([...NATURAL_EXPECTED, '', null])
        ->and(collect(['1', '2', '10', '100', '11', '20'])->sortByNatural(fn ($v) => $v, descending: true)->values()->all())
        ->toBe(['100', '20', '11', '10', '2', '1'])
        ->and(NaturalSort::compare('99999999999999999', '100000000000000000'))->toBeLessThan(0); // no float/int overflow
});

it('orders by the database: orderByNatural on osobné číslo, Vema, attendance and cost-centre numbers', function (string $field) {
    sameNameEmployees($field);

    expect(Employee::query()->orderByNatural($field)->pluck($field)->all())->toBe(NATURAL_EXPECTED)
        ->and(Employee::query()->orderByNatural($field, 'desc')->pluck($field)->all())->toBe(array_reverse(NATURAL_EXPECTED));
})->with(['osobne_cislo', 'vema_cislo', 'attendance_cislo', 'stredisko_cislo']);

it('puts empty values last on the database', function () {
    employee(['vema_cislo' => null, 'last_name' => 'A']);
    employee(['vema_cislo' => '10', 'last_name' => 'B']);
    employee(['vema_cislo' => '9', 'last_name' => 'C']);

    expect(Employee::query()->orderByNatural('vema_cislo')->pluck('vema_cislo')->all())->toBe(['9', '10', null]);
});

it('sorts the employees table by number: 1, 2, 10, 11, 20, 100', function (string $column) {
    $field = $column;
    $people = sameNameEmployees($field);
    $ordered = array_map(fn ($n) => $people[$n], NATURAL_EXPECTED);

    Livewire::test(ListEmployees::class)
        ->toggleAllTableColumns() // "Č. strediska" is hidden by default
        ->sortTable($column)
        ->assertCanSeeTableRecords($ordered, inOrder: true)
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords(array_reverse($ordered), inOrder: true);
})->with(['osobne_cislo', 'vema_cislo', 'stredisko_cislo']);

it('uses the number as natural tie-break after the name (slips, rosters, exports)', function () {
    sameNameEmployees();

    expect(Employee::query()->alphabetical()->pluck('osobne_cislo')->all())->toBe(NATURAL_EXPECTED);
});

it('orders the monthly Súhrn export naturally', function () {
    sameNameEmployees();
    $path = app(MonthlyMatrixXlsx::class)->write(MonthlyData::for('2026-11', includeWithoutOrders: true));

    $reader = new Reader;
    $reader->open($path);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        if ($sheet->getName() === 'Súhrn') {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
    }
    $reader->close();

    $numbers = collect($rows)->slice(1, count(NATURAL_EXPECTED))->pluck(0)->values()->all(); // then Spolu + note
    expect($numbers)->toBe(NATURAL_EXPECTED);
});
