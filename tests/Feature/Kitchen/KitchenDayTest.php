<?php

use App\Domain\Kitchen\DailyOverview;
use App\Domain\Ordering\Actor;
use App\Domain\Ordering\OrderingException;
use App\Domain\Ordering\OrderService;
use App\Domain\Ordering\SlotLockService;
use App\Enums\Role;
use App\Filament\Pages\KitchenDay;
use App\Filament\Widgets\TodayStats;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function () {
    travelToLocal('2026-11-10 07:30:00');
    $this->items = menuItems('2026-11-10', lunch(), 3);
    $s = app(OrderService::class);

    $this->people = collect([
        ['Žiak', 'Peter', 0], ['Baláž', 'Anna', 1], ['Čierna', 'Eva', 0], ['Adamov', 'Ján', 2], ['Novák', 'Ján', 0],
    ])->map(function ($p) use ($s) {
        $e = employee(['last_name' => $p[0], 'first_name' => $p[1]]);
        $s->place($e, $this->items[$p[2]], Actor::employee($e));

        return $e;
    });
    // Novák also has an extra; Čierna cancelled
    $novak = $this->people[4];
    $s->place($novak, $this->items[1], Actor::employee($novak), acceptExtra: true);
    $cierna = $this->people[2];
    $s->cancel($cierna->orders()->sole(), Actor::employee($cierna));

    // noise: another slot and another day
    $d = menuItems('2026-11-10', dinner(), 1);
    $s->place($this->people[0], $d[0], Actor::employee($this->people[0]));

    $this->kitchen = User::factory()->kitchen()->create();
});

it('computes totals per option and an alphabetical list of active orders', function () {
    $o = DailyOverview::for('2026-11-10', lunch());

    expect($o->total())->toBe(5)
        ->and($o->extraCount())->toBe(1)
        ->and($o->totals->map(fn ($t) => [$t['item']->number, $t['total'], $t['donated'], $t['extra']])->all())->toBe([
            [1, 2, 2, 0], [2, 2, 1, 1], [3, 1, 1, 0],
        ])
        ->and($o->rows->pluck('name')->all())->toBe(['Adamov Ján', 'Baláž Anna', 'Novák Ján', 'Novák Ján', 'Žiak Peter'])
        ->and($o->rows->pluck('extra')->all())->toBe([false, false, false, true, false]);
});

it('splits the list into two print columns reading down', function () {
    $cols = DailyOverview::for('2026-11-10', lunch())->twoColumns();

    expect($cols)->toHaveCount(3)
        ->and($cols[0][0]['name'])->toBe('Adamov Ján')->and($cols[0][1]['name'])->toBe('Novák Ján')
        ->and($cols[2][1])->toBeNull()->and($cols[2][2])->toBe(3);
});

it('loads the overview with a constant number of queries', function () {
    DB::enableQueryLog();
    DailyOverview::for('2026-11-10', lunch());
    $few = count(DB::getQueryLog());

    foreach (range(1, 20) as $i) {
        $e = employee();
        app(OrderService::class)->place($e, $this->items[0], Actor::employee($e));
    }

    DB::flushQueryLog();
    DailyOverview::for('2026-11-10', lunch());
    expect(count(DB::getQueryLog()))->toBe($few);
});

describe('page', function () {
    it('is available to kitchen, admin and payroll', function (Role $role) {
        $this->actingAs(User::factory()->withRoles($role)->create())
            ->get('/admin/kuchyna?datum=2026-11-10&jedlo=obed')
            ->assertOk()
            ->assertSee('Adamov Ján')
            ->assertSee('Menu 1');
    })->with([Role::Kitchen, Role::Admin, Role::Payroll]);

    it('defaults to today and the first slot and ignores bad parameters', function () {
        $this->actingAs($this->kitchen);
        Livewire::test(KitchenDay::class)->assertSet('date', '2026-11-10')->assertSet('slot', 'obed');
        Livewire::withQueryParams(['datum' => 'xx', 'jedlo' => 'olovrant'])->test(KitchenDay::class)
            ->assertSet('date', '2026-11-10')->assertSet('slot', 'obed');
    });

    it('navigates days and slots', function () {
        $this->actingAs($this->kitchen);
        Livewire::test(KitchenDay::class)
            ->call('shiftDay', 1)->assertSet('date', '2026-11-11')
            ->call('today')->assertSet('date', '2026-11-10')
            ->call('selectSlot', 'vecera')->assertSet('slot', 'vecera')->assertSee('Žiak Peter')->assertDontSee('Adamov Ján');
    });

    it('locks and unlocks right on the page (kitchen)', function () {
        $this->actingAs($this->kitchen);

        Livewire::test(KitchenDay::class)->assertSee('Objednávky otvorené')->call('lock')->assertSee('Uzamknuté');
        expect(app(SlotLockService::class)->isLocked('2026-11-10', lunch()))->toBeTrue();

        // employees can't order or cancel any more
        $e = employee();
        expect(fn () => app(OrderService::class)->place($e, $this->items[0], Actor::employee($e)))
            ->toThrow(OrderingException::class, 'Objednávky na obed sú už uzavreté.');

        Livewire::test(KitchenDay::class)->call('unlock');
        expect(app(SlotLockService::class)->isLocked('2026-11-10', lunch()))->toBeFalse()
            ->and(DB::table('activity_log')->where('log_name', 'locks')->pluck('causer_id')->unique()->all())->toBe([$this->kitchen->id]);
    });

    it('does not let payroll lock', function () {
        $this->actingAs(User::factory()->withRoles(Role::Payroll)->create());
        Livewire::test(KitchenDay::class)->assertDontSee('Uzamknúť')->call('lock')->assertForbidden();
        expect(app(SlotLockService::class)->isLocked('2026-11-10', lunch()))->toBeFalse();
    });

    it('refreshes itself while the day is current', function () {
        $this->actingAs($this->kitchen);
        Livewire::test(KitchenDay::class)->assertSeeHtml('wire:poll.60s');
        Livewire::withQueryParams(['datum' => '2026-11-01'])->test(KitchenDay::class)->assertDontSeeHtml('wire:poll.60s');
    });
});

describe('print, PDF, XLSX', function () {
    beforeEach(fn () => $this->actingAs($this->kitchen));

    it('renders the print page with header, checkboxes, extra mark and totals', function () {
        $this->get('/admin/kuchyna/tlac?datum=2026-11-10&jedlo=obed&tlac=1')
            ->assertOk()
            ->assertSee('Obed – utorok 10. 11. 2026')
            ->assertSee('Vytlačené 10. 11. 2026 07:30')
            ->assertSee('Spolu: 5')
            ->assertSee('EXTRA')
            ->assertSee('class="box"', false)
            ->assertSee('window.print()', false)
            ->assertSee('Objednávky ešte otvorené do 09:00!');
    });

    it('produces a PDF', function () {
        $r = $this->get('/admin/kuchyna/pdf?datum=2026-11-10&jedlo=obed');
        $r->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect(substr($r->getContent(), 0, 5))->toBe('%PDF-')
            ->and($r->headers->get('Content-Disposition'))->toContain('kuchyna_2026-11-10_obed.pdf');
    });

    it('produces an XLSX with the same content incl. osobné číslo', function () {
        $r = $this->get('/admin/kuchyna/xlsx?datum=2026-11-10&jedlo=obed');
        $r->assertOk()->assertDownload('kuchyna_2026-11-10_obed.xlsx');

        $reader = new Reader;
        $reader->open($r->getFile()->getPathname());
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();

        $adamov = Employee::query()->where('last_name', 'Adamov')->sole();
        expect($rows[0][0])->toBe('utorok 10. 11. 2026 – Obed')
            ->and($rows[2])->toBe(['Priezvisko', 'Meno', 'Osobné číslo', 'Stredisko', 'Menu', 'Jedlo', 'Typ', 'Poznámka'])
            ->and(array_slice($rows[3], 0, 5))->toBe(['Adamov', 'Ján', $adamov->osobne_cislo, (string) $adamov->department, 3])
            ->and(collect($rows)->last())->toBe(['Spolu', '', '', '', '', '', 4, 1, 5]);
    });

    it('validates parameters and protects the exports', function () {
        $this->get('/admin/kuchyna/xlsx?datum=10.11.2026&jedlo=obed')->assertSessionHasErrors('datum');
        $this->get('/admin/kuchyna/pdf?datum=2026-11-10&jedlo=olovrant')->assertNotFound();

        auth()->logout();
        $this->get('/admin/kuchyna/tlac?datum=2026-11-10&jedlo=obed')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get('/admin/kuchyna/tlac?datum=2026-11-10&jedlo=obed')->assertForbidden();
    });
});

it('shows today and tomorrow on the dashboard', function () {
    $this->actingAs($this->kitchen);

    Livewire::test(TodayStats::class)
        ->assertSee('Dnes – Obed')
        ->assertSee('Zajtra – Večera')
        ->assertSee('otvorené do 09:00');
});

it('shows the soup on the kitchen screen, print and Excel', function () {
    $this->items->first()->load('menu')->menu->update(['soup_name' => 'Kapustnica', 'soup_allergens' => '1,9']);
    $this->actingAs($this->kitchen);

    expect(DailyOverview::for('2026-11-10', lunch())->soup)->toBe('Kapustnica (1,9)')
        ->and(DailyOverview::for('2026-11-10', dinner())->soup)->toBeNull();

    Livewire::test(KitchenDay::class)->assertSee('Kapustnica (1,9)');
    $this->get('/admin/kuchyna/tlac?datum=2026-11-10&jedlo=obed')->assertSee('Polievka: Kapustnica (1,9)');

    $r = $this->get('/admin/kuchyna/xlsx?datum=2026-11-10&jedlo=obed');
    $reader = new Reader;
    $reader->open($r->getFile()->getPathname());
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }
    $reader->close();
    expect($rows[2])->toBe(['Polievka', 'Kapustnica (1,9)']);
});
