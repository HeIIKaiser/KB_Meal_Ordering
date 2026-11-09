<?php

use App\Domain\Menus\MenuImport;
use App\Domain\Menus\MenuWriter;
use App\Domain\Ordering\Actor;
use App\Domain\Ordering\OrderService;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Filament\Pages\ImportMenus;
use App\Filament\Pages\MenuWeek;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

function tmpCsv(string $content): string
{
    $p = tempnam(sys_get_temp_dir(), 'menu');
    file_put_contents($p, $content);

    return $p;
}

beforeEach(function () {
    travelToLocal('2026-11-10 07:00:00');
    $this->kitchen = User::factory()->kitchen()->create();
});

it('is available to kitchen and admin, not payroll', function () {
    $this->actingAs($this->kitchen)->get('/admin/menu')->assertOk();
    $this->actingAs($this->kitchen)->get('/admin/menu/import')->assertOk();
    $this->actingAs(User::factory()->admin()->create())->get('/admin/menu')->assertOk();
    $this->actingAs(User::factory()->withRoles(Role::Payroll)->create())->get('/admin/menu')->assertForbidden();
    $this->actingAs(User::factory()->withRoles(Role::Payroll)->create())->get('/admin/sablona/menu')->assertForbidden();
});

describe('import parsing', function () {
    it('parses the template with various date formats and reports row errors', function () {
        $csv = "datum;jedlo;cislo_menu;nazov;alergeny\n"
            ."16.11.2026;obed;1;Hovädzí guláš;1,3,7\n"
            ."2026-11-16;Večera;1;Palacinky;\"1, 3\"\n"
            ."17. 11. 2026;OBED;2;Rizoto;\n"
            ."31.11.2026;obed;1;Zlý dátum;\n"
            ."18.11.2026;olovrant;1;Neznáme jedlo;\n"
            ."18.11.2026;obed;x;Zlé číslo;\n"
            ."18.11.2026;obed;1;;\n"
            ."18.11.2026;obed;3;Zlé alergény;1,15\n"
            ."16.11.2026;obed;1;Duplicita;\n";

        $r = app(MenuImport::class)->preview(tmpCsv($csv), 'menu.csv');

        expect(collect($r['rows'])->map(fn ($x) => [$x['date'], $x['meal_slot_id'], $x['number'], $x['name'], $x['allergens']])->all())->toBe([
            ['2026-11-16', lunch()->id, 1, 'Hovädzí guláš', '1,3,7'],
            ['2026-11-16', dinner()->id, 1, 'Palacinky', '1, 3'],
            ['2026-11-17', lunch()->id, 2, 'Rizoto', ''],
        ])
            ->and(collect($r['errors'])->pluck('line')->all())->toBe([5, 6, 7, 8, 9, 10])
            ->and($r['errors'][0]['messages'][0])->toContain('Neplatný dátum')
            ->and($r['errors'][5]['messages'][0])->toContain('Duplicitný riadok')
            ->and($r['plan']->pluck('action')->unique()->all())->toBe([MenuWriter::CREATE])
            ->and($r['plan'][1]['allergens'])->toBe('1,3'); // normalised
    });

    it('reads XLSX files with real date cells', function () {
        $path = tempnam(sys_get_temp_dir(), 'm').'.xlsx';
        $w = new Writer;
        $w->openToFile($path);
        $w->addRow(Row::fromValues(['Dátum', 'Jedlo', 'Číslo menu', 'Názov', 'Alergény']));
        $w->addRow(Row::fromValues([new DateTimeImmutable('2026-11-19'), 'obed', 1, 'Segedín', '1,7']));
        $w->close();

        $r = app(MenuImport::class)->preview($path);
        expect($r['errors'])->toBe([])->and($r['rows'][0]['date'])->toBe('2026-11-19');
    });
});

describe('menu writer', function () {
    it('creates, updates, deactivates — and lists employees affected by a change', function () {
        $items = menuItems('2026-11-10', lunch(), 2); // "Menu 1", "Menu 2"
        $emp = employee(['last_name' => 'Novák', 'first_name' => 'Ján']);
        app(OrderService::class)->place($emp, $items[0], Actor::employee($emp));

        $w = app(MenuWriter::class);
        $plan = $w->plan([
            ['date' => '2026-11-10', 'meal_slot_id' => lunch()->id, 'number' => 1, 'name' => 'Iné jedlo', 'allergens' => ''],
            ['date' => '2026-11-10', 'meal_slot_id' => lunch()->id, 'number' => 2, 'name' => '', 'allergens' => ''],
            ['date' => '2026-11-10', 'meal_slot_id' => lunch()->id, 'number' => 3, 'name' => 'Nové', 'allergens' => '7'],
            ['date' => '2026-11-11', 'meal_slot_id' => lunch()->id, 'number' => 1, 'name' => '', 'allergens' => ''],
        ]);

        expect($plan->pluck('action')->all())->toBe([MenuWriter::UPDATE, MenuWriter::REMOVE, MenuWriter::CREATE, MenuWriter::UNCHANGED])
            ->and($plan[0]['affected'])->toBe(['Novák Ján'])
            ->and($plan[1]['affected'])->toBe([])
            ->and(MenuWriter::impact($plan)->keys()->first())->toContain('Menu 1');

        expect($w->apply($plan))->toBe(3);

        $items->each->refresh();
        expect($items[0]->name)->toBe('Iné jedlo')
            ->and($items[1]->is_active)->toBeFalse()
            ->and(MenuItem::query()->where('number', 3)->sole()->allergens)->toBe('7')
            ->and(Order::sole()->status)->toBe(OrderStatus::Active); // orders are never touched
    });

    it('reactivates a removed option when it gets a name again', function () {
        $items = menuItems('2026-11-10', lunch(), 1);
        $items[0]->update(['is_active' => false]);

        $w = app(MenuWriter::class);
        $w->apply($w->plan([['date' => '2026-11-10', 'meal_slot_id' => lunch()->id, 'number' => 1, 'name' => 'Späť', 'allergens' => null]]));

        expect($items[0]->fresh()->is_active)->toBeTrue();
    });
});

describe('weekly grid page', function () {
    beforeEach(fn () => $this->actingAs($this->kitchen));

    it('opens next week by default with empty rows', function () {
        Livewire::test(MenuWeek::class)
            ->assertSet('week', '2026-11-16')
            ->assertCount('grid', 14)
            ->assertSet('grid.2026-11-16|'.lunch()->id.'.0.name', '')
            ->assertSee('Večera')
            ->assertSeeHtml('wire:model="grid.2026-11-16|'.lunch()->id.'.0.name"');
    });

    it('saves typed options and reloads them', function () {
        $key = '2026-11-16|'.lunch()->id;

        Livewire::test(MenuWeek::class)
            ->set("grid.$key.0.name", 'Kurací rezeň')
            ->set("grid.$key.0.allergens", '1, 3,7')
            ->call('addRow', $key)
            ->set("grid.$key.3.name", 'Štvrté menu')
            ->call('save')
            ->assertSet('impact', null)
            ->assertSet("grid.$key.0.allergens", '1,3,7')
            ->assertSet("grid.$key.3.name", 'Štvrté menu');

        expect(MenuItem::query()->pluck('name', 'number')->all())->toBe([1 => 'Kurací rezeň', 4 => 'Štvrté menu']);
    });

    it('validates allergens', function () {
        $key = '2026-11-16|'.lunch()->id;
        Livewire::test(MenuWeek::class)
            ->set("grid.$key.0.name", 'X')->set("grid.$key.0.allergens", 'mlieko')
            ->call('save')
            ->assertHasErrors("grid.$key.0.allergens");
        expect(MenuItem::count())->toBe(0);
    });

    it('requires confirmation when an ordered option is removed and lists the affected employees', function () {
        $items = menuItems('2026-11-10', lunch(), 2);
        $emp = employee(['last_name' => 'Kováčová', 'first_name' => 'Anna']);
        app(OrderService::class)->place($emp, $items[1], Actor::employee($emp));
        $key = '2026-11-10|'.lunch()->id;

        $c = Livewire::test(MenuWeek::class, ['week' => '2026-11-09'])
            ->set("grid.$key.1.name", '')
            ->call('save')
            ->assertSee('Kováčová Anna');

        expect($c->get('impact'))->toHaveCount(1)
            ->and($items[1]->fresh()->is_active)->toBeTrue();

        $c->call('save', true)->assertSet('impact', null);
        expect($items[1]->fresh()->is_active)->toBeFalse();
    });

    it('copies the previous week into empty cells only', function () {
        menuItems('2026-11-09', lunch(), 2);
        $key = '2026-11-16|'.lunch()->id;
        $other = '2026-11-16|'.dinner()->id;

        Livewire::test(MenuWeek::class)
            ->set("grid.$other.0.name", 'Vlastné')
            ->call('copyPreviousWeek')
            ->assertSet("grid.$key.0.name", MenuItem::query()->where('number', 1)->value('name'))
            ->assertSet("grid.$other.0.name", 'Vlastné');
    });
});

it('imports through the page and opens the imported week', function () {
    $this->actingAs($this->kitchen);

    Livewire::test(ImportMenus::class)
        ->set('file', UploadedFile::fake()->createWithContent('m.csv', "datum,jedlo,cislo_menu,nazov,alergeny\n23.11.2026,obed,1,Bryndzové halušky,\"1,3,7\"\n"))
        ->call('analyze')
        ->assertHasNoErrors()
        ->assertSee('Bryndzové halušky')
        ->call('commit')
        ->assertRedirect(MenuWeek::getUrl(['tyzden' => '2026-11-23']));

    expect(MenuItem::sole()->name)->toBe('Bryndzové halušky');
});
