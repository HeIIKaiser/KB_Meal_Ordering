<?php

use App\Domain\Attendance\AttendanceRoster;
use App\Domain\Attendance\EmployeeMatcher;
use App\Domain\Attendance\Finalizer;
use App\Domain\Exports\Invoice\BillingXlsxFormat;
use App\Domain\Exports\Invoice\InvoiceData;
use App\Domain\Exports\MonthlyData;
use App\Domain\Exports\MonthlyMatrixXlsx;
use App\Domain\Exports\OrderListExport;
use App\Domain\Exports\Payroll\PayrollData;
use App\Domain\Ordering\Actor;
use App\Domain\Ordering\FixedPriceResolver;
use App\Domain\Ordering\OrderingException;
use App\Domain\Ordering\OrderService;
use App\Domain\Tickets\MealGroupService;
use App\Domain\Tickets\TicketAllocation;
use App\Enums\PricingMode;
use App\Models\FixedPrice;
use App\Models\MealGroup;
use App\Models\MealGroupMode;
use App\Models\PriceType;
use App\Support\LocalTime;
use OpenSpout\Reader\XLSX\Reader;
use Spatie\Activitylog\Models\Activity;

function fixedGroup(int $cents = 500, string $from = '2026-10-01', ?int $cap = null, string $settlement = 'invoice'): MealGroup
{
    $g = MealGroup::query()->create(['name' => 'SZČO', 'default_tickets' => 0, 'settlement' => $settlement, 'max_per_slot' => $cap, 'is_active' => true]);
    MealGroupMode::query()->create(['meal_group_id' => $g->id, 'mode' => PricingMode::Fixed, 'effective_from' => $from]);
    FixedPrice::query()->create(['meal_group_id' => $g->id, 'amount_cents' => $cents, 'valid_from' => '2026-01-01']);

    return $g;
}

function tempsGroup(string $from = '2026-10-01'): MealGroup
{
    $g = ticketGroup(20, $from, 'Brigádnici');
    $g->update(['estimate_source' => 'orders']);

    return $g;
}

function xlsxSheets(string $path): array
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
    travelToLocal('2026-10-12 07:00:00');
    $this->admin = adminUser();
});

describe('temps: estimate by orders', function () {
    beforeEach(function () {
        $this->group = tempsGroup();
        $this->temp = joinGroup(employee(['osobne_cislo' => '0300']), $this->group, '2026-10-01');
    });

    it('prices every order at 1,50 € while the month is open, beyond any plan or group count', function () {
        $this->group->update(['default_tickets' => 0]);
        $items = menuItems('2026-10-12', lunch(), 3);
        $orders = orderAll($this->temp, [$items[0], $items[1], $items[2]]);

        expect(collect($orders)->map->fresh()->pluck('unit_price_cents')->all())->toBe([150, 150, 150])
            ->and(app(OrderService::class)->quote($this->temp, '2026-10-13', lunch())->isExtra())->toBeFalse()
            ->and(app(TicketAllocation::class)->allocationWithSource($this->temp, '2026-10'))->toBe(['tickets' => 3, 'source' => 'orders_estimate']);
    });

    it('moves the latest orders above the attendance entitlement to 6 € at finalisation and flags it', function () {
        $i = lunchItemsFor(['2026-10-12', '2026-10-13', '2026-10-14']);
        orderAll($this->temp, array_values($i));
        syncAttendance('2026-10', ['300' => ['2026-10-12' => [0, '6:00'], '2026-10-13' => [0, 0], '2026-10-14' => [0, 0]]]); // worked 1 day
        expect(prices($this->temp))->toBe([150, 150, 150]); // estimate by orders ignores the (empty) plan

        travelToLocal('2026-11-11 09:00:00');
        $preview = collect(app(Finalizer::class)->preview('2026-10', $this->admin))->keyBy('osobne_cislo')['0300'];
        expect($preview['flags'])->toMatchArray(['orders_estimate' => true, 'to_extra' => 2])
            ->and($preview['tickets'])->toBe([0, 1]);

        app(Finalizer::class)->finalize('2026-10', $this->admin);
        expect(prices($this->temp))->toBe([150, 600, 600]);
    });

    it('keeps the order-based count for a temp without any attendance (flagged)', function () {
        orderAll($this->temp, [firstItem('2026-10-12')]);
        travelToLocal('2026-11-11 09:00:00');

        $row = collect(app(Finalizer::class)->finalize('2026-10', $this->admin))->keyBy('osobne_cislo')['0300'];
        expect($row['flags']['no_data'])->toBeTrue()->and(prices($this->temp))->toBe([150]);
    });

    it('leaves plan-based ticket groups unchanged', function () {
        $plan = joinGroup(employee(['osobne_cislo' => '0301']), ticketGroup(1, '2026-10-01', '8 h'), '2026-10-01');
        $items = menuItems('2026-10-12', lunch(), 2);
        orderAll($plan, [$items[0], $items[1]]);

        expect(prices($plan))->toBe([150, 600]);
    });
});

describe('self-employed: fixed price', function () {
    beforeEach(function () {
        $this->group = fixedGroup(500);
        $this->szco = joinGroup(employee(['osobne_cislo' => '0400', 'last_name' => 'Živnostník', 'billing_name' => 'Ján Živnostník', 'ico' => '12345678']), $this->group, '2026-10-01');
    });

    it('charges the group price without a confirmation dialog and snapshots it on the order', function () {
        $order = app(OrderService::class)->place($this->szco, firstItem('2026-10-12'), Actor::employee($this->szco));

        expect($order->unit_price_cents)->toBe(500)
            ->and($order->pricing_mode)->toBe(PricingMode::Fixed)
            ->and($order->priceType->code)->toBe(PriceType::FIXED)
            ->and($order->donated_guard)->toBeNull();

        FixedPrice::query()->create(['meal_group_id' => $this->group->id, 'amount_cents' => 700, 'valid_from' => '2026-10-01']);
        expect($order->fresh()->unit_price_cents)->toBe(500); // snapshot
    });

    it('prefers the person price, then a meal-specific price, and versions by meal date', function () {
        $r = app(FixedPriceResolver::class);
        FixedPrice::query()->create(['meal_group_id' => $this->group->id, 'meal_slot_id' => dinner()->id, 'amount_cents' => 450, 'valid_from' => '2026-01-01']);
        expect($r->amount($this->szco, '2026-10-12', lunch()))->toBe(500)
            ->and($r->amount($this->szco, '2026-10-12', dinner()))->toBe(450);

        FixedPrice::query()->create(['employee_id' => $this->szco->id, 'amount_cents' => 550, 'valid_from' => '2026-10-15']);
        expect($r->amount($this->szco, '2026-10-14', lunch()))->toBe(500)   // person price not yet valid for that meal date
            ->and($r->amount($this->szco, '2026-10-15', lunch()))->toBe(550)
            ->and($r->amount($this->szco, '2026-10-15', dinner()))->toBe(550); // person price beats the group's dinner price
    });

    it('refuses orders without any price, and enforces the optional cap', function () {
        $noPrice = joinGroup(employee(), MealGroup::query()->create(['name' => 'Bez ceny', 'settlement' => 'invoice']), '2026-10-01');
        MealGroupMode::query()->create(['meal_group_id' => MealGroup::query()->where('name', 'Bez ceny')->value('id'), 'mode' => 'fixed', 'effective_from' => '2026-10-01']);
        expect(fn () => app(OrderService::class)->place($noPrice, firstItem('2026-10-12'), Actor::employee($noPrice)))
            ->toThrow(OrderingException::class, 'nie je nastavená pevná cena');

        $this->group->update(['max_per_slot' => 1]);
        $items = menuItems('2026-10-13', lunch(), 2);
        orderAll($this->szco, [$items[0]]);
        expect(fn () => app(OrderService::class)->place($this->szco, $items[1], Actor::employee($this->szco)))
            ->toThrow(OrderingException::class, 'maximálny počet');
    });

    it('is never repriced at finalisation, has no attendance warnings and refuses price-type corrections', function () {
        [$order] = orderAll($this->szco, [firstItem('2026-10-12')]);
        syncAttendance('2026-10', ['400' => ['2026-10-12' => ['8:00', '8:00']]]);

        expect(app(AttendanceRoster::class)->employees('2026-10')->pluck('employee.id')->all())->not->toContain($this->szco->id);
        travelToLocal('2026-11-11 09:00:00');
        app(Finalizer::class)->finalize('2026-10', $this->admin);
        expect($order->fresh()->unit_price_cents)->toBe(500)
            ->and(fn () => app(OrderService::class)->changePriceType($order, PriceType::byCode('extra'), Actor::staff($this->admin, 'x')))
            ->toThrow(OrderingException::class, 'Pevná cena');
    });

    it('gives orders the fixed price when a group switches into fixed mode', function () {
        $g = MealGroup::query()->create(['name' => 'Prechod', 'settlement' => 'invoice']);
        FixedPrice::query()->create(['meal_group_id' => $g->id, 'amount_cents' => 520, 'valid_from' => '2026-01-01']);
        $emp = joinGroup(employee(), $g, '2026-10-01');
        $items = menuItems('2026-10-14', lunch(), 2);
        orderAll($emp, [$items[0], $items[1]]);
        expect(prices($emp))->toBe([150, 600]);

        app(MealGroupService::class)->switchMode($g, PricingMode::Fixed, '2026-10-14', $this->admin);
        expect(prices($emp))->toBe([520, 520]);
    });
});

describe('settlement', function () {
    beforeEach(function () {
        $this->szco = joinGroup(employee(['osobne_cislo' => '0400', 'vema_cislo' => '400', 'last_name' => 'Živnostník', 'first_name' => 'Ján',
            'billing_name' => 'Ján Živnostník – ZJ', 'ico' => '12345678']), fixedGroup(500), '2026-10-01');
        $this->staff = employee(['osobne_cislo' => '0401', 'vema_cislo' => '401', 'last_name' => 'Zamestnanec']);
        $i = menuItems('2026-10-12', lunch(), 2);
        $d = menuItems('2026-10-12', dinner(), 1);
        orderAll($this->szco, [$i[0], $i[1], $d[0]]);
        orderAll($this->staff, [$i[0]]);
    });

    it('keeps invoiced people out of the Vema export', function () {
        expect(PayrollData::for('2026-10')->rows->map(fn ($r) => $r->employee->osobne_cislo)->all())->toBe(['0401']);
    });

    it('exports Fakturácia only for invoiced people, with totals equal to the monthly matrix', function () {
        $data = InvoiceData::for('2026-10');
        expect($data->rows)->toHaveCount(1);
        $row = $data->rows->first();
        expect([$row->lunches, $row->dinners, $row->other, $row->unitPrices, $row->totalCents])->toBe([2, 1, 0, [500], 1500]);

        $sheets = xlsxSheets(app(BillingXlsxFormat::class)->write($data));
        expect($sheets['Súhrn'][0])->toBe(array_keys(BillingXlsxFormat::summaryColumns()))
            ->and($sheets['Súhrn'][1])->toMatchArray([0 => '0400', 3 => 'Ján Živnostník – ZJ', 4 => '12345678', 9 => 2, 10 => 1, 12 => '5,00 €', 13 => 15])
            ->and(count($sheets['Detail']))->toBe(4);

        $monthly = MonthlyData::for('2026-10');
        $entry = $monthly->employees->first(fn ($e) => $e['employee']->osobne_cislo === '0400');
        expect(MonthlyData::totalCents($entry))->toBe($data->totalCents())
            ->and($entry['settlement'])->toBe('faktura');
    });

    it('marks the settlement in Súhrn and the flat list, and counts fixed orders', function () {
        $s = xlsxSheets(app(MonthlyMatrixXlsx::class)->write(MonthlyData::for('2026-10')))['Súhrn'];
        $h = $s[0];
        $szco = collect($s)->first(fn ($r) => $r[0] === '0400');
        expect($szco[array_search('Vysporiadanie', $h)])->toBe('faktura')
            ->and($szco[array_search('Pevná cena ks', $h)])->toBe(3)
            ->and($szco[array_search('Suma €', $h)])->toBe(15)
            ->and(collect($s)->first(fn ($r) => $r[0] === '0401')[array_search('Vysporiadanie', $h)])->toBe('mzdy');

        $csv = file_get_contents(app(OrderListExport::class)->csv(LocalTime::date('2026-10-01'), LocalTime::date('2026-10-31')));
        $lines = array_map(fn ($l) => str_getcsv($l, ';', '"', ''), array_filter(explode("\n", trim(substr($csv, 3)))));
        $row = collect($lines)->first(fn ($l) => $l[2] === '0400');
        expect([$row[8], $row[9], $row[14], $row[15]])->toBe(['pevna', '5,00', 'pevna', 'faktura']);
    });
});

describe('osobné číslo change', function () {
    it('keeps orders, history and attendance pairing after the number changes', function () {
        $emp = employee(['osobne_cislo' => '0500']);
        orderAll($emp, [firstItem('2026-10-12')]);
        $emp->update(['osobne_cislo' => '0777']);

        expect($emp->orders()->count())->toBe(1)
            ->and(Activity::query()->where('subject_id', $emp->id)->where('log_name', 'employees')->latest('id')->first()->properties['old']['osobne_cislo'])->toBe('0500');
        syncAttendance('2026-10', ['777' => ['2026-10-12' => ['8:00', '8:00']]]);
        expect(app(EmployeeMatcher::class)->find('777')?->id)->toBe($emp->id);
    });
});
