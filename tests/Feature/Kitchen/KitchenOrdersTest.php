<?php

use App\Domain\Ordering\Actor;
use App\Domain\Ordering\OrderingException;
use App\Domain\Ordering\OrderService;
use App\Domain\Ordering\SlotLockService;
use App\Domain\Tickets\ClosedMonths;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Filament\Pages\KitchenDay;
use App\Filament\Pages\OrderingSettings;
use App\Models\Order;
use App\Models\PriceType;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
 * The kitchen may order and cancel on behalf of employees (people forget to order),
 * with the same rules as admin; corrections, settings and closed months stay off-limits.
 */

beforeEach(function () {
    travelToLocal('2026-11-26 07:30:00');            // lunch cutoff 09:00 → still open
    $this->lunch = menuItems('2026-11-26', lunch(), 2);
    $this->emp = employee(['last_name' => 'Zabudnutá', 'first_name' => 'Eva']);
    $this->kitchen = User::factory()->kitchen()->create();
    $this->payroll = User::factory()->withRoles(Role::Payroll)->create();
});

function kitchenOrders(): OrderService
{
    return app(OrderService::class);
}

describe('service rules', function () {
    it('lets the kitchen order and cancel before the cutoff; price type is automatic', function () {
        $first = kitchenOrders()->place($this->emp, $this->lunch[0], Actor::staff($this->kitchen));
        $second = kitchenOrders()->place($this->emp, $this->lunch[1], Actor::staff($this->kitchen), acceptExtra: true);

        expect($first->priceType->code)->toBe(PriceType::DONATED)
            ->and($second->priceType->code)->toBe(PriceType::EXTRA)
            ->and($first->created_by_user_id)->toBe($this->kitchen->id);

        $cancelled = kitchenOrders()->cancel($second, Actor::staff($this->kitchen));
        expect($cancelled->status)->toBe(OrderStatus::Cancelled)
            ->and($cancelled->cancelled_by_user_id)->toBe($this->kitchen->id);
    });

    it('after the cutoff or on a locked slot requires a reason, and audits it with the staff user', function () {
        travelToLocal('2026-11-26 10:00:00'); // after the 09:00 cutoff

        expect(fn () => kitchenOrders()->place($this->emp, $this->lunch[0], Actor::staff($this->kitchen)))
            ->toThrow(OrderingException::class, 'povinný dôvod');

        $order = kitchenOrders()->place($this->emp, $this->lunch[0], Actor::staff($this->kitchen, 'Zabudla si objednať'));
        expect(fn () => kitchenOrders()->cancel($order, Actor::staff($this->kitchen)))->toThrow(OrderingException::class, 'povinný dôvod');
        kitchenOrders()->cancel($order, Actor::staff($this->kitchen, 'Odišla domov'));

        $log = Activity::query()->where('subject_type', $order->getMorphClass())->where('subject_id', $order->id)->orderBy('id')->get();
        expect($log->pluck('event')->all())->toBe(['created', 'cancelled'])
            ->and($log->pluck('causer_id')->unique()->all())->toBe([$this->kitchen->id])
            ->and($log->pluck('properties')->map(fn ($p) => $p['reason'] ?? null)->all())->toBe(['Zabudla si objednať', 'Odišla domov']);
    });

    it('requires a reason on a manually locked slot before the cutoff too', function () {
        app(SlotLockService::class)->lock('2026-11-26', lunch(), $this->kitchen);

        expect(fn () => kitchenOrders()->place($this->emp, $this->lunch[0], Actor::staff($this->kitchen)))->toThrow(OrderingException::class, 'povinný dôvod');
        expect(kitchenOrders()->place($this->emp, $this->lunch[0], Actor::staff($this->kitchen, 'Telefonovala'))->status)->toBe(OrderStatus::Active);
    });

    it('does not let the kitchen change the price type or switch the item', function () {
        $order = kitchenOrders()->place($this->emp, $this->lunch[0], Actor::staff($this->kitchen));

        expect(fn () => kitchenOrders()->changePriceType($order, PriceType::byCode('extra'), Actor::staff($this->kitchen, 'x')))->toThrow(AuthorizationException::class)
            ->and(fn () => kitchenOrders()->switchItem($order, $this->lunch[1], Actor::staff($this->kitchen, 'x')))->toThrow(AuthorizationException::class);
    });

    it('keeps closed months closed for the kitchen (and everyone)', function () {
        $old = menuItems('2026-10-20', lunch(), 1);
        app(ClosedMonths::class)->close('2026-10', adminUser());

        expect(fn () => kitchenOrders()->place($this->emp, $old[0], Actor::staff($this->kitchen, 'neskoro')))->toThrow(OrderingException::class)
            ->and(fn () => kitchenOrders()->place($this->emp, $old[0], Actor::staff(adminUser(), 'neskoro')))->toThrow(OrderingException::class);
    });

    it('keeps payroll and inactive kitchen accounts read-only', function () {
        $order = kitchenOrders()->place($this->emp, $this->lunch[0], Actor::employee($this->emp));

        expect(fn () => kitchenOrders()->place($this->emp, $this->lunch[1], Actor::staff($this->payroll, 'x')))->toThrow(AuthorizationException::class)
            ->and(fn () => kitchenOrders()->cancel($order, Actor::staff($this->payroll, 'x')))->toThrow(AuthorizationException::class)
            ->and($this->payroll->can('create', Order::class))->toBeFalse()
            ->and($this->payroll->can('cancel', $order))->toBeFalse()
            ->and($this->kitchen->can('update', $order))->toBeFalse();

        $this->kitchen->update(['is_active' => false]);
        expect(fn () => kitchenOrders()->cancel($order, Actor::staff($this->kitchen, 'x')))->toThrow(AuthorizationException::class);
    });

    it('limits the kitchen to N days back when set (admin unlimited; 0 = no limit)', function () {
        $old = menuItems('2026-11-20', lunch(), 1); // 6 days back
        app(Settings::class)->set(Settings::KITCHEN_DAYS_BACK, 3);

        expect(fn () => kitchenOrders()->place($this->emp, $old[0], Actor::staff($this->kitchen, 'zabudla')))
            ->toThrow(OrderingException::class, 'najviac 3 dni dozadu');
        $byAdmin = kitchenOrders()->place($this->emp, $old[0], Actor::staff(adminUser(), 'zabudla'));
        expect(fn () => kitchenOrders()->cancel($byAdmin, Actor::staff($this->kitchen, 'omyl')))->toThrow(OrderingException::class, 'dozadu');

        app(Settings::class)->set(Settings::KITCHEN_DAYS_BACK, 0);
        expect(kitchenOrders()->cancel($byAdmin, Actor::staff($this->kitchen, 'omyl'))->status)->toBe(OrderStatus::Cancelled);
    });
});

describe('Denný prehľad', function () {
    it('adds an order with the date and meal of the view prefilled, then shows it', function () {
        $this->actingAs($this->kitchen);

        Livewire::test(KitchenDay::class, ['date' => '2026-11-26', 'slot' => 'obed'])
            ->assertActionVisible('addOrder')
            ->mountAction('addOrder')
            ->assertActionDataSet(['date' => '2026-11-26', 'meal_slot_id' => lunch()->id])
            ->setActionData(['employee_id' => $this->emp->id, 'menu_item_id' => $this->lunch[1]->id])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertSee('Zoznam (1)')
            ->assertSee('Zabudnutá Eva');

        expect(Order::query()->active()->where('employee_id', $this->emp->id)->sole()->menu_item_id)->toBe($this->lunch[1]->id);
    });

    it('cancels a row with a reason after the cutoff and the totals refresh', function () {
        $order = kitchenOrders()->place($this->emp, $this->lunch[0], Actor::employee($this->emp));
        travelToLocal('2026-11-26 10:00:00');
        $this->actingAs($this->kitchen);

        Livewire::test(KitchenDay::class, ['date' => '2026-11-26', 'slot' => 'obed'])
            ->assertSee('Zoznam (1)')
            ->callAction('cancelOrder', [], ['order' => $order->id]) // no reason → refused, dialog stays open
            ->assertNotified('Nedá sa');
        expect($order->fresh()->status)->toBe(OrderStatus::Active);

        Livewire::test(KitchenDay::class, ['date' => '2026-11-26', 'slot' => 'obed'])
            ->callAction('cancelOrder', ['reason' => 'Nepríde'], ['order' => $order->id])
            ->assertSee('Zoznam (0)')
            ->assertSee('Žiadne objednávky');
        expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    });

    it('hides add/cancel from payroll (read-only)', function () {
        $order = kitchenOrders()->place($this->emp, $this->lunch[0], Actor::employee($this->emp));
        $this->actingAs($this->payroll);

        Livewire::test(KitchenDay::class, ['date' => '2026-11-26', 'slot' => 'obed'])
            ->assertActionHidden('addOrder')
            ->assertSee('Zabudnutá Eva')
            ->assertDontSee('Zrušiť');
        expect(KitchenDay::canOrder())->toBeFalse();
    });

    it('keeps settings admin-only (the kitchen limit is set there)', function () {
        $this->actingAs($this->kitchen)->get(OrderingSettings::getUrl())->assertForbidden();

        $this->actingAs(adminUser());
        Livewire::test(OrderingSettings::class)->set('kitchenDaysBack', 7)->call('save')->assertHasNoErrors();
        expect(app(Settings::class)->kitchenDaysBack())->toBe(7);
    });
});
