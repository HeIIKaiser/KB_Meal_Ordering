<?php

use App\Domain\Ordering\Actor;
use App\Domain\Ordering\OrderService;
use App\Domain\Ordering\SlotLockService;
use App\Enums\OrderStatus;
use App\Livewire\OrderScreen;
use App\Models\Order;
use App\Models\PriceType;
use App\Models\User;
use App\Support\Settings;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    travelToLocal('2026-11-10 07:30:00'); // Tuesday
    $this->lunch = menuItems('2026-11-10', lunch(), 3);
    $this->dinner = menuItems('2026-11-10', dinner(), 2);
    $this->emp = employee();
    $this->actingAs($this->emp, 'employee');
});

function screen(): Testable
{
    return Livewire::test(OrderScreen::class);
}

it('renders the week with today selected and the menu options', function () {
    $this->get('/')->assertOk()->assertSee('Objednávky', false);

    screen()
        ->assertSet('week', '2026-11-09')
        ->assertSet('selected', '2026-11-10')
        ->assertSee($this->lunch[0]->name)
        ->assertSee('otvorené do 09:00')
        ->assertSee('otvorené do 14:00');
});

it('orders with one tap as donated', function () {
    screen()->call('choose', $this->lunch[0]->id)
        ->assertSet('dialog', null)
        ->assertSet('flash.type', 'ok')
        ->assertSee('Objednané · 1,50 €');

    $order = Order::sole();
    expect($order->employee_id)->toBe($this->emp->id)
        ->and($order->priceType->code)->toBe(PriceType::DONATED);
});

it('asks before cancelling when the ordered option is tapped again', function () {
    $c = screen()->call('choose', $this->lunch[0]->id)->call('choose', $this->lunch[0]->id)
        ->assertSet('dialog.type', 'cancel')
        ->assertSee('Zrušiť objednávku?');

    expect(Order::sole()->status)->toBe(OrderStatus::Active);

    $c->call('confirmCancel')->assertSet('dialog', null)->assertSet('flash.type', 'ok');
    expect(Order::sole()->status)->toBe(OrderStatus::Cancelled);
});

it('offers switch or extra when another option in the slot is tapped', function () {
    $c = screen()->call('choose', $this->lunch[0]->id)->call('choose', $this->lunch[1]->id)
        ->assertSet('dialog.type', 'change')
        ->assertSee('Zmeniť objednávku?')
        ->assertSee('Dokúpiť ďalšie jedlo za 6,00 €');

    $c->call('confirmSwitch');
    expect(Order::sole()->menu_item_id)->toBe($this->lunch[1]->id);
});

it('adds an extra at 6,00 € from the change dialog', function () {
    screen()->call('choose', $this->lunch[0]->id)->call('choose', $this->lunch[1]->id)->call('confirmExtra')
        ->assertSee('Dokupované · 6,00 €');

    expect(Order::query()->where('price_type_id', PriceType::byCode('extra')->id)->sole()->unit_price_cents)->toBe(600);
});

it('only offers switching once an extra limit set by the admin is reached', function () {
    app(Settings::class)->set(Settings::MAX_EXTRA_PER_SLOT, 1);

    screen()->call('choose', $this->lunch[0]->id)
        ->call('choose', $this->lunch[1]->id)->call('confirmExtra')
        ->call('choose', $this->lunch[2]->id)
        ->assertSet('dialog.type', 'change')
        ->assertSet('dialog.extra_label', null);
});

it('asks to confirm the price whenever an order would be extra', function () {
    // e.g. a donated meal was ordered from another device meanwhile; simulated by
    // making donated unavailable so the very first order would be extra
    PriceType::byCode('donated')->update(['per_slot_daily_limit' => 0]);

    $c = screen()->call('choose', $this->lunch[0]->id)
        ->assertSet('dialog.type', 'extra')
        ->assertSee('Toto jedlo bude za 6,00 €.')
        ->assertSee('Áno, objednať za 6,00 €');
    expect(Order::count())->toBe(0);

    $c->call('confirmExtra');
    expect(Order::sole()->unit_price_cents)->toBe(600);
});

it('ignores confirmation calls without an open dialog', function () {
    screen()->call('confirmExtra')->call('confirmCancel')->call('confirmSwitch');
    expect(Order::count())->toBe(0);
});

it('shows a Slovak error after the cutoff and does not order', function () {
    travelToLocal('2026-11-10 09:00:00');

    screen()->call('choose', $this->lunch[0]->id)
        ->assertSet('flash', ['type' => 'error', 'text' => 'Objednávky na obed sú už uzavreté.']);
    expect(Order::count())->toBe(0);
});

it('shows a locked slot as locked', function () {
    app(SlotLockService::class)->lock('2026-11-10', dinner(), User::factory()->kitchen()->create());

    screen()->assertSee('uzamknuté')
        ->call('choose', $this->dinner[0]->id)
        ->assertSet('flash.type', 'error');
    expect(Order::count())->toBe(0);
});

it('does not show other employees\' orders', function () {
    $other = employee();
    app(OrderService::class)->place($other, $this->lunch[0], Actor::employee($other));

    screen()->assertDontSee('Objednané · 1,50 €');
});

it('navigates between weeks via the URL parameter', function () {
    screen()->call('nextWeek')
        ->assertSet('week', '2026-11-16')
        ->assertSet('selected', '2026-11-16')
        ->call('previousWeek')->call('previousWeek')
        ->assertSet('week', '2026-11-02')
        ->assertSet('selected', '2026-11-08')
        ->call('thisWeek')
        ->assertSet('selected', '2026-11-10');

    Livewire::withQueryParams(['tyzden' => 'nonsense'])->test(OrderScreen::class)->assertSet('week', '2026-11-09');
    Livewire::withQueryParams(['tyzden' => '2026-11-19'])->test(OrderScreen::class)->assertSet('week', '2026-11-16');
});

it('cannot open dialogs by tampering with the locked dialog property', function () {
    screen()->set('dialog', ['type' => 'cancel', 'order' => 1]);
})->throws(CannotUpdateLockedPropertyException::class);

it('shows the soup of the day on the lunch card (not orderable)', function () {
    $this->lunch->first()->load('menu')->menu->update(['soup_name' => 'Fazuľová polievka', 'soup_allergens' => '1,9']);

    screen()->assertSee('Polievka:')->assertSee('Fazuľová polievka')->assertSee('alergény 1,9')
        ->assertDontSeeHtml('wire:click="choose(0)"');
    expect(Order::count())->toBe(0);
});
