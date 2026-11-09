<?php

use App\Domain\Exports\MonthlyData;
use App\Domain\Exports\MonthlyMatrixXlsx;
use App\Domain\Ordering\Actor;
use App\Domain\Ordering\OrderService;
use App\Enums\Role;
use App\Models\Employee;
use App\Models\MealSlot;
use App\Models\Menu;
use App\Models\Order;
use App\Models\Price;
use App\Models\PriceType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;

/** Place an order as if it happened at 07:00 on the meal day (before cutoff). */
function orderOn(Employee $e, string $date, MealSlot $slot, bool $extra = false, int $option = 0): Order
{
    travelToLocal("$date 07:00:00");
    $menu = Menu::query()->where('date', $date)->where('meal_slot_id', $slot->id)->first();
    $items = $menu ? $menu->items()->get() : menuItems($date, $slot, 2);

    return app(OrderService::class)->place($e, $items[$option], Actor::employee($e), acceptExtra: $extra);
}

/** @return array<string, list<list<mixed>>> sheet name → rows (empty rows skipped by the reader) */
function readXlsx(string $path): array
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
    // donated costs 2,00 € for meals from 15 Nov
    Price::create(['price_type_id' => PriceType::byCode('donated')->id, 'amount_cents' => 200, 'valid_from' => '2026-11-15']);

    $this->a = employee(['last_name' => 'Novák', 'first_name' => 'Ján', 'osobne_cislo' => '0123', 'department' => 'Kuchyňa']);
    $this->b = employee(['last_name' => 'Adamová', 'first_name' => 'Eva', 'osobne_cislo' => '2000']);
    $this->idle = employee(['last_name' => 'Žiadny', 'first_name' => 'Igor', 'osobne_cislo' => '3000']);
    $this->gone = employee(['last_name' => 'Bývalý', 'first_name' => 'Juraj']);

    orderOn($this->a, '2026-10-31', lunch());                      // other month
    orderOn($this->a, '2026-11-02', lunch());                      // donated 1,50
    orderOn($this->a, '2026-11-02', dinner());                     // donated 1,50 → day 2 = 2 donated
    orderOn($this->a, '2026-11-02', lunch(), extra: true, option: 1); // extra 6,00
    $cancel = orderOn($this->a, '2026-11-03', lunch());
    app(OrderService::class)->cancel($cancel, Actor::employee($this->a)); // cancelled → not counted
    orderOn($this->a, '2026-11-30', lunch());                      // donated 2,00 (new price)
    orderOn($this->a, '2026-12-01', lunch());                      // other month
    orderOn($this->b, '2026-11-16', dinner());                     // donated 2,00
    orderOn($this->gone, '2026-11-05', lunch());                   // ate in November …
    $this->gone->update(['is_active' => false]);                   // … left the company since

    travelToLocal('2026-12-02 10:00:00');
    // repricing after the fact never changes the snapshot
    Price::create(['price_type_id' => PriceType::byCode('donated')->id, 'amount_cents' => 999, 'valid_from' => '2026-11-01']);
});

describe('monthly numbers', function () {
    it('counts per day and sums snapshot prices, active orders of the month only', function () {
        $d = MonthlyData::for('2026-11');
        $a = $d->employees->first(fn ($e) => $e['employee']->is($this->a));
        $donated = PriceType::byCode('donated')->id;
        $extra = PriceType::byCode('extra')->id;

        expect($d->daysInMonth())->toBe(30)
            ->and(MonthlyData::count($a, $donated, null, 2))->toBe(2)   // lunch + dinner donated on the same day
            ->and(MonthlyData::count($a, $extra, null, 2))->toBe(1)
            ->and(MonthlyData::count($a, null, null, 3))->toBe(0)       // cancelled
            ->and(MonthlyData::count($a, $donated))->toBe(3)
            ->and(MonthlyData::count($a, $extra))->toBe(1)
            ->and(MonthlyData::cents($a, $donated))->toBe(500)          // 1,50 + 1,50 + 2,00
            ->and(MonthlyData::cents($a, $extra))->toBe(600)
            ->and(MonthlyData::cents($a))->toBe(1100)
            ->and(MonthlyData::count($a, $donated, lunch()->id))->toBe(2)
            ->and(MonthlyData::count($a, $donated, dinner()->id))->toBe(1);
    });

    it('includes everyone who ate in the month (also people deactivated since), sorted by surname', function () {
        expect(MonthlyData::for('2026-11')->employees->map(fn ($e) => $e['employee']->last_name)->all())
            ->toBe(['Adamová', 'Bývalý', 'Novák']);

        expect(MonthlyData::for('2026-11', includeWithoutOrders: true)->employees->map(fn ($e) => $e['employee']->last_name)->all())
            ->toBe(['Adamová', 'Bývalý', 'Novák', 'Žiadny']);
    });

    it('needs a constant number of queries', function () {
        DB::enableQueryLog();
        MonthlyData::for('2026-11');
        $n = count(DB::getQueryLog());
        foreach (range(1, 10) as $i) {
            orderOn(employee(), '2026-11-20', lunch());
        }
        DB::flushQueryLog();
        MonthlyData::for('2026-11');
        expect(count(DB::getQueryLog()))->toBe($n);
    });
});

describe('monthly XLSX', function () {
    it('has the matrix and the summary sheet with the right numbers', function () {
        $sheets = readXlsx(app(MonthlyMatrixXlsx::class)->write(MonthlyData::for('2026-11')));
        expect(array_keys($sheets))->toBe(['Mesiac', 'Súhrn']);

        $m = $sheets['Mesiac'];
        expect($m[0][0])->toBe('Stravovanie – november 2026')
            ->and($m[2][0])->toBe('Cena stravy je odhadom a po porovnaní s dochádzkou sa môže zmeniť.');

        $header = collect($m)->first(fn ($r) => $r[0] === 'Zamestnanec');
        expect(array_slice($header, 2, 3))->toBe([1, 2, 3])->and(array_slice($header, -2))->toBe(['Spolu', 'Suma €']);

        $i = collect($m)->search(fn ($r) => $r[0] === 'Novák Ján (osobné č. 0123)');
        expect($m[$i][1])->toBe('november 2026')
            ->and(array_slice($m[$i], 2, 3))->toBe(['Ne', 'Po', 'Ut'])     // 1. 11. 2026 is a Sunday
            ->and($m[$i + 1][1])->toBe('Zamestnanecká strava')
            ->and(array_slice($m[$i + 1], 2, 3))->toBe([0, 2, 0])
            ->and(array_slice($m[$i + 1], -2))->toBe([3, 5])
            ->and($m[$i + 2][1])->toBe('Dokupovaná strava')
            ->and(array_slice($m[$i + 2], -2))->toBe([1, 6])
            ->and($m[$i + 3][1])->toBe('Spolu')
            ->and(array_slice($m[$i + 3], -2))->toBe([4, 11]);

        $s = $sheets['Súhrn'];
        expect($s[0])->toBe(['Osobné číslo', 'Priezvisko', 'Meno', 'Stredisko', 'Zamestnanecká strava ks', 'Dokupovaná strava ks', 'Zamestnanecká strava €', 'Dokupovaná strava €', 'Suma €'])
            ->and(collect($s)->first(fn ($r) => $r[0] === '0123'))->toBe(['0123', 'Novák', 'Ján', 'Kuchyňa', 3, 1, 5, 6, 11])
            ->and(collect($s)->first(fn ($r) => $r[0] === 'Spolu'))->toBe(['Spolu', '', '', '', 5, 1, 8.5, 6, 14.5])
            ->and(collect($s)->last())->toBe(['Cena stravy je odhadom a po porovnaní s dochádzkou sa môže zmeniť.']);
    });

    it('splits rows by slot on request', function () {
        $m = readXlsx(app(MonthlyMatrixXlsx::class)->write(MonthlyData::for('2026-11'), splitBySlot: true))['Mesiac'];
        $i = collect($m)->search(fn ($r) => str_starts_with((string) $r[0], 'Novák Ján'));

        expect(collect(array_slice($m, $i + 1, 4))->pluck(1)->all())->toBe(['Zamestnanecká strava – obed', 'Zamestnanecká strava – večera', 'Dokupovaná strava – obed', 'Dokupovaná strava – večera'])
            ->and($m[$i + 1][3])->toBe(1)->and($m[$i + 2][3])->toBe(1)->and($m[$i + 3][3])->toBe(1)->and($m[$i + 4][3])->toBe(0);
    });
});

describe('download routes', function () {
    it('lets payroll and admin download the monthly export with the right file name', function (Role $role) {
        $this->actingAs(User::factory()->withRoles($role)->create())
            ->get('/admin/exporty/mesiac?mesiac=2026-11&po_jedlach=1')
            ->assertOk()
            ->assertDownload('stravovanie_2026-11_mesacny.xlsx');

        expect(DB::table('activity_log')->where('log_name', 'exports')->value('description'))->toBe('Export: mesačný prehľad 2026-11');
    })->with([Role::Payroll, Role::Admin]);

    it('denies exports to kitchen and guests and validates input', function () {
        $this->get('/admin/exporty/mesiac?mesiac=2026-11')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->kitchen()->create())->get('/admin/exporty/zoznam?od=2026-11-01&do=2026-11-30&format=csv')->assertForbidden();
        $this->actingAs(User::factory()->kitchen()->create())->get('/admin/exporty')->assertForbidden();

        $payroll = User::factory()->withRoles(Role::Payroll)->create();
        $this->actingAs($payroll)->get('/admin/exporty')->assertOk()->assertSee('Mesačný prehľad')->assertSee('Cena stravy je odhadom');
        $this->actingAs($payroll)->get('/admin/exporty/mesiac?mesiac=11/2026')->assertSessionHasErrors('mesiac');
        $this->actingAs($payroll)->get('/admin/exporty/zoznam?od=2026-11-30&do=2026-11-01&format=xlsx')->assertSessionHasErrors('do');
        $this->actingAs($payroll)->get('/admin/exporty/zoznam?od=2026-11-01&do=2026-11-30&format=pdf')->assertSessionHasErrors('format');
        $this->actingAs($payroll)->get('/admin/exporty/zoznam?od=2024-01-01&do=2026-11-30&format=csv')->assertStatus(422);
    });

    it('exports the flat list as CSV incl. cancelled orders and who created them', function () {
        $admin = User::factory()->admin()->create(['name' => 'Roman']);
        travelToLocal('2026-11-20 07:00:00');
        $items = menuItems('2026-11-20', lunch(), 1);
        app(OrderService::class)->place($this->idle, $items[0], Actor::staff($admin));
        travelToLocal('2026-12-02 10:00:00');

        $r = $this->actingAs(User::factory()->withRoles(Role::Payroll)->create())
            ->get('/admin/exporty/zoznam?od=2026-11-01&do=2026-11-30&format=csv');
        $r->assertOk()->assertDownload('stravovanie_2026-11_zoznam.csv');

        $csv = file_get_contents($r->getFile()->getPathname());
        expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue();
        $lines = array_map(fn ($l) => str_getcsv($l, ';', '"', ''), array_filter(explode("\n", trim(substr($csv, 3)))));

        expect($lines[0])->toBe(['datum', 'jedlo', 'osobne_cislo', 'priezvisko', 'meno', 'stredisko', 'menu_cislo', 'menu_nazov', 'typ', 'cena', 'stav', 'vytvorene', 'zrusene', 'vytvoril'])
            ->and(count($lines) - 1)->toBe(8); // 7 in November incl. 1 cancelled + Igor's

        $first = $lines[1];
        expect([$first[0], $first[1], $first[2], $first[3], $first[8], $first[9], $first[10], $first[13]])
            ->toBe(['2026-11-02', 'obed', '0123', 'Novák', 'zamestnanecka', '1,50', 'aktivna', 'sám'])
            ->and($first[11])->toBe('2026-11-02 07:00:00'); // local time

        $cancelled = collect($lines)->first(fn ($l) => $l[10] === 'zrusena');
        expect($cancelled[0])->toBe('2026-11-03')->and($cancelled[12])->toBe('2026-11-03 07:00:00');

        expect(collect($lines)->first(fn ($l) => $l[2] === '3000')[13])->toBe('Roman');
    });

    it('exports the flat list as XLSX for any range', function () {
        $r = $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/exporty/zoznam?od=2026-10-31&do=2026-11-02&format=xlsx');
        $r->assertOk()->assertDownload('stravovanie_2026-10-31_2026-11-02_zoznam.xlsx');

        $rows = readXlsx($r->getFile()->getPathname())['Objednávky'];
        expect($rows)->toHaveCount(5) // header + Oct 31 + 3 on Nov 2
            ->and($rows[1][0])->toBeInstanceOf(DateTimeImmutable::class)
            ->and($rows[1][0]->format('Y-m-d'))->toBe('2026-10-31')
            ->and($rows[1][2])->toBe('0123')
            ->and($rows[1][9])->toBe(1.5);
    });
});
