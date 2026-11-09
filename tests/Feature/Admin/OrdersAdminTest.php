<?php

use App\Domain\Ordering\Actor;
use App\Domain\Ordering\OrderService;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\PriceType;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    travelToLocal('2026-11-10 07:30:00');
    $this->admin = User::factory()->admin()->create();
    $this->items = menuItems('2026-11-10', lunch(), 2);
    $this->emp = employee(['last_name' => 'Gajdoš', 'first_name' => 'Milan']);
});

function placeFor($emp, $item, bool $extra = false): Order
{
    return app(OrderService::class)->place($emp, $item, Actor::employee($emp), acceptExtra: $extra);
}

it('lists orders for every staff role', function (Role $role) {
    placeFor($this->emp, $this->items[0]);

    $this->actingAs(User::factory()->withRoles($role)->create())
        ->get('/admin/orders')->assertOk()->assertSee('Gajdoš Milan');
})->with([Role::Admin, Role::Kitchen, Role::Payroll]);

it('filters by employee and status and hides corrections from non-admins', function () {
    $o = placeFor($this->emp, $this->items[0]);
    $other = employee();
    $o2 = placeFor($other, $this->items[1]);

    $this->actingAs($this->admin);
    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords([$o, $o2])
        ->filterTable('employee_id', $this->emp->id)
        ->assertCanSeeTableRecords([$o])
        ->assertCanNotSeeTableRecords([$o2]);

    $this->actingAs(User::factory()->kitchen()->create());
    Livewire::test(ListOrders::class)
        ->assertTableActionHidden('createOnBehalf')
        ->assertTableActionHidden('cancelOrder', $o);
});

it('lets an admin order on behalf of an employee', function () {
    $this->actingAs($this->admin);

    Livewire::test(ListOrders::class)
        ->callTableAction('createOnBehalf', data: [
            'employee_id' => $this->emp->id, 'date' => '2026-11-10', 'meal_slot_id' => lunch()->id,
            'menu_item_id' => $this->items[1]->id, 'note' => 'nemá telefón',
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Objednané');

    $order = Order::sole();
    expect($order->created_by_user_id)->toBe($this->admin->id)
        ->and($order->note)->toBe('nemá telefón')
        ->and($order->priceType->code)->toBe('donated');
});

it('requires a reason for corrections after the cutoff', function () {
    $order = placeFor($this->emp, $this->items[0]);
    travelToLocal('2026-11-10 11:00:00');
    $this->actingAs($this->admin);

    Livewire::test(ListOrders::class)
        ->callTableAction('cancelOrder', $order, ['reason' => ''])
        ->assertNotified('Nedá sa');
    expect($order->fresh()->status)->toBe(OrderStatus::Active);

    Livewire::test(ListOrders::class)
        ->callTableAction('cancelOrder', $order, ['reason' => 'PN'])
        ->assertNotified('Objednávka zrušená');
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

it('switches the dish and changes the price type with a reason', function () {
    $order = placeFor($this->emp, $this->items[0]);
    $this->actingAs($this->admin);

    Livewire::test(ListOrders::class)
        ->callTableAction('switchItem', $order, ['menu_item_id' => $this->items[1]->id])
        ->assertNotified('Jedlo zmenené');
    expect($order->fresh()->menu_item_id)->toBe($this->items[1]->id);

    Livewire::test(ListOrders::class)
        ->callTableAction('changeType', $order, ['price_type_id' => PriceType::byCode('extra')->id, 'reason' => ''])
        ->assertHasTableActionErrors(['reason']);

    Livewire::test(ListOrders::class)
        ->callTableAction('changeType', $order, ['price_type_id' => PriceType::byCode('extra')->id, 'reason' => 'oprava'])
        ->assertNotified('Typ ceny zmenený');
    expect($order->fresh()->unit_price_cents)->toBe(600);
});

it('shows refusals from the order rules as a notification', function () {
    placeFor($this->emp, $this->items[0]);
    $extra = placeFor($this->emp, $this->items[1], extra: true);
    $this->actingAs($this->admin);

    Livewire::test(ListOrders::class)
        ->callTableAction('changeType', $extra, ['price_type_id' => PriceType::byCode('donated')->id, 'reason' => 'x'])
        ->assertNotified('Nedá sa');
});

describe('audit log', function () {
    it('is admin only', function () {
        $this->actingAs(User::factory()->kitchen()->create())->get('/admin/audit')->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/audit')->assertOk();
    });

    it('shows who did what and why', function () {
        $order = placeFor($this->emp, $this->items[0]);
        travelToLocal('2026-11-10 11:00:00');
        app(OrderService::class)->cancel($order, Actor::staff($this->admin, 'Dovolenka'));

        $this->actingAs($this->admin);
        Livewire::test(ListActivities::class)
            ->assertSee('Objednávka zrušená')
            ->assertSee('Dovolenka')
            ->assertSee('Gajdoš Milan (zamestnanec)')
            ->assertSee($this->admin->name)
            ->filterTable('log_name', ['prices'])
            ->assertDontSee('Dovolenka');
    });
});
