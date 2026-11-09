<?php

use App\Domain\Kitchen\WeeklyMenu;
use App\Enums\Role;
use App\Livewire\OrderScreen;
use App\Models\ClosedDay;
use App\Models\Employee;
use App\Models\PriceType;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    travelToLocal('2026-11-10 07:00:00');
    foreach (range(16, 22) as $d) {
        $items = menuItems("2026-11-$d", lunch(), 2);
        $items->first()->load('menu')->menu->update(['soup_name' => "Polievka $d", 'soup_allergens' => '1,9']);
        menuItems("2026-11-$d", dinner(), 1);
    }
    ClosedDay::create(['date' => '2026-11-18', 'note' => 'Sanitárny deň']);
    ClosedDay::create(['date' => '2026-11-22', 'meal_slot_id' => dinner()->id, 'note' => 'Sviatok']);
});

it('builds a Monday–Sunday grid: soup, lunch options, dinner, closed days', function () {
    $w = WeeklyMenu::for('2026-11-19'); // any day of the week

    expect($w->monday->toDateString())->toBe('2026-11-16')
        ->and($w->period())->toBe('16. 11. – 22. 11. 2026')
        ->and(collect($w->columns)->pluck('label')->all())->toBe(['Polievka', 'Obed – Menu 1', 'Obed – Menu 2', 'Večera'])
        ->and($w->days)->toHaveCount(7)
        ->and($w->days[0]['cells'][0]['name'])->toBe('Polievka 16')
        ->and($w->days[0]['cells'][0]['allergens'])->toBe('1,9')
        ->and($w->days[2]['closed'])->toBe('Uzavreté – Sanitárny deň')
        ->and($w->days[6]['cells'][3]['name'])->toBe('Uzavreté – Sviatok')
        ->and($w->days[6]['cells'][1])->not->toBeNull();
});

it('prints for kitchen, admin and payroll, and fits on one A4 page as PDF', function (Role $role) {
    $this->actingAs(User::factory()->withRoles($role)->create());

    $this->get('/admin/jedalny-listok/tlac?tyzden=2026-11-16')
        ->assertOk()
        ->assertSee('Jedálny lístok')
        ->assertSee('16. 11. – 22. 11. 2026')
        ->assertSee('Štvrtok')                // multibyte capitalisation
        ->assertSee('Polievka 16')
        ->assertSee('Uzavreté – Sanitárny deň')
        ->assertSee('1 obilniny obsahujúce lepok'); // allergen legend

    $r = $this->get('/admin/jedalny-listok/pdf?tyzden=2026-11-16');
    $r->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($r->headers->get('Content-Disposition'))->toContain('jedalny_listok_2026-11-16.pdf')
        ->and(preg_match_all('#/Type\s*/Page[^s]#', $r->getContent()))->toBe(1);
})->with([Role::Kitchen, Role::Admin, Role::Payroll]);

it('guards the weekly print and validates the week', function () {
    $this->get('/admin/jedalny-listok/tlac')->assertRedirect('/admin/login');
    $this->actingAs(User::factory()->create())->get('/admin/jedalny-listok/tlac')->assertForbidden();
    $this->actingAs(User::factory()->kitchen()->create())->get('/admin/jedalny-listok/tlac?tyzden=16.11.2026')->assertSessionHasErrors('tyzden');
});

it('links the weekly print from the menu editor', function () {
    $this->actingAs(User::factory()->kitchen()->create())
        ->get('/admin/menu?tyzden=2026-11-16')
        ->assertSee('Tlačiť týždeň')
        ->assertSee(route('menu.week.print', ['tyzden' => '2026-11-16', 'tlac' => 1]));
});

it('prints one A4 login sheet per employee with live cutoffs and prices', function () {
    $admin = User::factory()->admin()->create();
    $a = employee(['first_name' => 'Ján', 'last_name' => 'Novák', 'osobne_cislo' => '5001']);
    $b = employee(['first_name' => 'Eva', 'last_name' => 'Tichá', 'osobne_cislo' => '5002']);
    $b->setPin('7788', Employee::PIN_BY_EMPLOYEE);
    $b->save();
    lunch()->update(['cutoff_time' => '08:30:00']);
    config(['app.url' => 'https://obedy.example.sk/']);

    $html = $this->actingAs($admin)->get(route('admin.slips', ['zamestnanci' => $a->id.','.$b->id]))->assertOk()->getContent();

    expect(substr_count($html, 'class="page"'))->toBe(2)
        // Big highlighted address for PC users: full https:// form, no trailing slash, on every sheet
        ->and(substr_count($html, '<div class="addr">https://obedy.example.sk</div>'))->toBe(2)
        ->and($html)->toContain('Obed do 08:30 v ten istý deň')
        ->and($html)->toContain('1,50 € – prvý obed a prvá večera v daný deň (zamestnanecká strava)')
        ->and($html)->toContain('6,00 € – každé ďalšie jedlo v ten istý deň (dokupovaná strava)')
        ->and($html)->toContain('1234')                       // Novák's printable PIN
        ->and($html)->not->toContain('7788')                  // Tichá's own PIN is never shown
        ->and($html)->toContain('PIN si zmenil(a) sám/sama');

    $this->get(route('admin.slips', ['zamestnanci' => $a->id, 'format' => 'male']))
        ->assertOk()->assertSee('A4 pre každého')->assertDontSee('class="page"', false);
});

it('renamed the price types (Zamestnanecká strava / Dokupovaná strava)', function () {
    expect(PriceType::query()->orderBy('sort')->pluck('name')->all())->toBe(['Zamestnanecká strava', 'Dokupovaná strava']);

    // the migration renames existing databases too
    PriceType::query()->where('code', 'donated')->update(['name' => 'Darované']);
    (include database_path('migrations/2026_11_10_000300_rename_price_types.php'))->up();
    expect(PriceType::byCode('donated')->fresh()->name)->toBe('Zamestnanecká strava');
});

it('gives the desktop week one shared row grid so slots line up', function () {
    $e = employee();
    $this->actingAs($e, 'employee');
    $this->withoutVite();

    // lunch: header + soup + 2 options = 4 rows; dinner: header + 1 option = 2; plus day title = 7
    Livewire::withQueryParams(['tyzden' => '2026-11-16'])->test(OrderScreen::class)
        ->assertSeeHtml('--week-rows: 7')
        ->assertSeeHtml('--slot-rows: 4')
        ->assertSeeHtml('--slot-rows: 2');
});
