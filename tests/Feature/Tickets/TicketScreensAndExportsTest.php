<?php

use App\Domain\Exports\MonthlyData;
use App\Domain\Exports\MonthlyMatrixXlsx;
use App\Domain\Exports\OrderListExport;
use App\Domain\Tickets\ClosedMonths;
use App\Domain\Tickets\MealGroupService;
use App\Enums\PricingMode;
use App\Livewire\OrderScreen;
use App\Models\MealGroup;
use App\Models\TicketOverride;
use App\Support\LocalTime;
use App\Support\Money;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function () {
    $this->withoutVite();
    travelToLocal('2026-11-10 07:30:00'); // Tuesday
    $this->group = ticketGroup(20);
    $this->emp = joinGroup(employee(['last_name' => 'Lístková', 'osobne_cislo' => '0900']), $this->group);
});

function ticketScreen(): Testable
{
    return Livewire::test(OrderScreen::class);
}

function sheets(string $path): array
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

describe('order screen', function () {
    beforeEach(fn () => $this->actingAs($this->emp, 'employee'));

    it('shows the tickets left for the month and lets the employee order more than one lunch at 1,50 €', function () {
        $items = menuItems('2026-11-10', lunch(), 2);

        ticketScreen()
            ->assertSee('Zostáva 20 z 20 lístkov – november')
            ->call('choose', $items[0]->id)
            ->assertSee('Zostáva 19 z 20 lístkov – november')
            ->call('choose', $items[1]->id)
            ->assertSee('Dokúpiť ďalšie jedlo za 1,50 € (lístok)')
            ->call('confirmExtra')
            ->assertSee('Zostáva 18 z 20 lístkov – november')
            ->call('choose', $items[1]->id)
            ->assertSee('Dokúpiť ďalšie rovnaké jedlo za 1,50 € (lístok)');

        expect(prices($this->emp))->toBe([150, 150]);
    });

    it('says when the tickets are used up and asks to confirm 6 €', function () {
        TicketOverride::query()->create(['employee_id' => $this->emp->id, 'month' => '2026-11', 'tickets' => 1]);
        $items = menuItems('2026-11-10', lunch(), 2);

        ticketScreen()
            ->call('choose', $items[0]->id)
            ->assertSee('Lístky minuté – ďalšie jedlá po 6,00 € (november)')
            ->call('choose', $items[1]->id)
            ->call('confirmExtra')
            ->assertSee('Objednané: Menu 2');
        expect(prices($this->emp))->toBe([150, 600]);
    });

    it('tells the employee when an earlier order moves a later meal to 6 €', function () {
        TicketOverride::query()->create(['employee_id' => $this->emp->id, 'month' => '2026-11', 'tickets' => 1]);
        orderAll($this->emp, [firstItem('2026-11-12')]);
        $earlier = firstItem('2026-11-11');

        ticketScreen()
            ->call('choose', $earlier->id)
            ->assertSet('dialog.type', 'extra')
            ->assertSee('Lístky na november sú minuté. Toto jedlo bude za 6,00 €.')
            ->call('confirmExtra')
            ->assertSee('Objednané: Menu 1 – '.$earlier->name.' (1,50 €). Obed Št 12. 11. Menu 1 je teraz za 6,00 €.');
    });

    it('shows no chip in daily mode', function () {
        $this->actingAs(employee(), 'employee');
        menuItems('2026-11-10', lunch(), 1);

        ticketScreen()->assertDontSee('lístkov');
    });
});

describe('Môj mesiac', function () {
    beforeEach(fn () => $this->actingAs($this->emp, 'employee'));

    it('shows allocated, used and left tickets with the attendance note', function () {
        orderAll($this->emp, [firstItem('2026-11-10'), firstItem('2026-11-11')]);

        $this->get('/moj-mesiac')->assertOk()
            ->assertSee('pridelené 20 · použité 2')
            ->assertSee('zostáva 18')
            ->assertSee(Money::TICKETS_NOTE)
            ->assertSee(Money::ESTIMATE_DISCLAIMER);
    });

    it('shows final prices for a closed month', function () {
        orderAll($this->emp, [firstItem('2026-11-10')]);
        app(ClosedMonths::class)->close('2026-11', adminUser());

        $this->get('/moj-mesiac')->assertSee(Money::CLOSED_NOTE)->assertDontSee(Money::ESTIMATE_DISCLAIMER);
    });
});

describe('exports', function () {
    it('adds group, mode and ticket columns; a mid-month switch is "zmiesany" with tickets for the ticket part only', function () {
        // A daily-mode group switched to tickets from the 12th (3 tickets).
        $mixedGroup = MealGroup::query()->create(['name' => '8 h', 'default_tickets' => 3, 'is_active' => true]);
        $mixed = joinGroup(employee(['last_name' => 'Zmiešaná', 'osobne_cislo' => '0901']), $mixedGroup);
        $i = lunchItemsFor(['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13']);
        orderAll($mixed, [$i['2026-11-10'], $i['2026-11-12'], $i['2026-11-13']]);
        app(MealGroupService::class)->switchMode($mixedGroup, PricingMode::Tickets, '2026-11-12', adminUser());
        orderAll($this->emp, [$i['2026-11-10'], $i['2026-11-11']]);
        $daily = employee(['last_name' => 'Denná', 'osobne_cislo' => '0902']);
        orderAll($daily, [$i['2026-11-10']]);

        $s = sheets(app(MonthlyMatrixXlsx::class)->write(MonthlyData::for('2026-11')))['Súhrn'];
        $row = fn ($n) => array_slice(collect($s)->first(fn ($r) => $r[0] === $n), 9);

        expect($row('0900'))->toBe(['12 h', 'listky', 20, 2, 18])
            ->and($row('0901'))->toBe(['8 h', 'zmiesany', 3, 2, 1])
            ->and(array_slice($row('0902'), 0, 2))->toBe(['', 'denny'])
            ->and(collect($s)->last())->toBe([Money::ESTIMATE_DISCLAIMER.' '.Money::TICKETS_NOTE]);

        $m = sheets(app(MonthlyMatrixXlsx::class)->write(MonthlyData::for('2026-11')))['Mesiac'];
        expect(collect($m)->contains(fn ($r) => ($r[2] ?? null) === 'pridelené 20 · použité 2 · prepadnuté 18'))->toBeTrue();

        $csv = file_get_contents(app(OrderListExport::class)->csv(LocalTime::date('2026-11-01'), LocalTime::date('2026-11-30')));
        $lines = array_map(fn ($l) => str_getcsv($l, ';', '"', ''), array_filter(explode("\n", trim(substr($csv, 3)))));
        expect(collect($lines)->slice(1)->groupBy(14)->map->count()->all())->toBe(['denny' => 2, 'listky' => 4]);
    });

    it('says the prices are final for a closed month', function () {
        orderAll($this->emp, [firstItem('2026-11-10')]);
        app(ClosedMonths::class)->close('2026-11', adminUser());

        $s = sheets(app(MonthlyMatrixXlsx::class)->write(MonthlyData::for('2026-11')));
        expect(collect($s['Súhrn'])->last())->toBe([Money::CLOSED_NOTE])
            ->and($s['Mesiac'][2])->toBe([Money::CLOSED_NOTE]);
    });

    it('shows the month state on the export page', function () {
        $this->actingAs(adminUser());
        app(ClosedMonths::class)->close('2026-10', adminUser());

        $this->get('/admin/exporty')->assertOk()->assertSee('október 2026 – uzavretý (konečné ceny)')->assertSee('november 2026 – otvorený (odhad)');
    });
});
