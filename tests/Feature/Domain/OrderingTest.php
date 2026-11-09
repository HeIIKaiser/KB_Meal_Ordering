<?php

use App\Domain\Ordering\Actor;
use App\Domain\Ordering\ExtraConfirmationRequired;
use App\Domain\Ordering\OrderingException;
use App\Domain\Ordering\OrderService;
use App\Domain\Ordering\SlotLockService;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\ClosedDay;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Price;
use App\Models\PriceType;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

function orders(): OrderService
{
    return app(OrderService::class);
}

function admin(): User
{
    return User::factory()->admin()->create();
}

beforeEach(function () {
    travelToLocal('2026-11-10 07:30:00');
    $this->lunchItems = menuItems('2026-11-10', lunch(), 3);
    $this->dinnerItems = menuItems('2026-11-10', dinner(), 2);
    $this->emp = employee();
    $this->me = Actor::employee($this->emp);
});

describe('automatic price typing', function () {
    it('makes the first order in a slot donated at 1,50 €', function () {
        $order = orders()->place($this->emp, $this->lunchItems[0], $this->me);

        expect($order->priceType->code)->toBe(PriceType::DONATED)
            ->and($order->unit_price_cents)->toBe(150)
            ->and($order->status)->toBe(OrderStatus::Active)
            ->and($order->created_by_user_id)->toBeNull()
            ->and($order->date->toDateString())->toBe('2026-11-10');
    });

    it('allows one donated lunch and one donated dinner on the same day', function () {
        $a = orders()->place($this->emp, $this->lunchItems[0], $this->me);
        $b = orders()->place($this->emp, $this->dinnerItems[0], $this->me);

        expect($a->priceType->code)->toBe(PriceType::DONATED)
            ->and($b->priceType->code)->toBe(PriceType::DONATED);
    });

    it('requires explicit confirmation before an extra order', function () {
        orders()->place($this->emp, $this->lunchItems[0], $this->me);

        try {
            orders()->place($this->emp, $this->lunchItems[1], $this->me);
            $this->fail('Expected ExtraConfirmationRequired');
        } catch (ExtraConfirmationRequired $e) {
            expect($e->getMessage())->toBe('Toto jedlo bude za 6,00 €.')
                ->and($e->quote->unitPriceCents)->toBe(600);
        }

        expect(Order::count())->toBe(1);
    });

    it('a double tap on the same option never creates a second order silently', function () {
        orders()->place($this->emp, $this->lunchItems[0], $this->me);

        expect(fn () => orders()->place($this->emp, $this->lunchItems[0], $this->me))
            ->toThrow(ExtraConfirmationRequired::class);
        expect(Order::count())->toBe(1);
    });

    it('makes the second order extra at 6,00 € when confirmed', function () {
        orders()->place($this->emp, $this->lunchItems[0], $this->me);
        $extra = orders()->place($this->emp, $this->lunchItems[1], $this->me, acceptExtra: true);

        expect($extra->priceType->code)->toBe(PriceType::EXTRA)
            ->and($extra->unit_price_cents)->toBe(600);
    });

    it('allows unlimited extra orders by default (each at 6,00 €)', function () {
        orders()->place($this->emp, $this->lunchItems[0], $this->me);
        foreach (range(1, 5) as $i) {
            $extra = orders()->place($this->emp, $this->lunchItems[$i % 3], $this->me, acceptExtra: true);
            expect($extra->priceType->code)->toBe(PriceType::EXTRA)->and($extra->unit_price_cents)->toBe(600);
        }

        expect(Order::count())->toBe(6)
            ->and(Order::query()->where('price_type_id', PriceType::byCode('donated')->id)->count())->toBe(1);
    });

    it('can cap extra orders per slot per day with the max_extra_per_slot setting', function () {
        app(Settings::class)->set(Settings::MAX_EXTRA_PER_SLOT, 1);
        orders()->place($this->emp, $this->lunchItems[0], $this->me);
        orders()->place($this->emp, $this->lunchItems[1], $this->me, acceptExtra: true);

        expect(fn () => orders()->place($this->emp, $this->lunchItems[2], $this->me, acceptExtra: true))
            ->toThrow(OrderingException::class, 'Na obed v tento deň už máte objednaný maximálny počet jedál.');

        app(Settings::class)->set(Settings::MAX_EXTRA_PER_SLOT, 2);
        expect(orders()->place($this->emp, $this->lunchItems[2], $this->me, acceptExtra: true)->priceType->code)->toBe(PriceType::EXTRA);

        app(Settings::class)->set(Settings::MAX_EXTRA_PER_SLOT, null); // back to unlimited
        expect(app(Settings::class)->maxExtraPerSlot())->toBeNull();
        orders()->place($this->emp, $this->lunchItems[0], $this->me, acceptExtra: true);
    });

    it('quotes the next order', function () {
        expect(orders()->quote($this->emp, '2026-11-10', lunch())->isExtra())->toBeFalse();
        orders()->place($this->emp, $this->lunchItems[0], $this->me);
        expect(orders()->quote($this->emp, '2026-11-10', lunch()))
            ->isExtra()->toBeTrue()
            ->unitPriceCents->toBe(600);
    });

    it('counts limits per employee', function () {
        $other = employee();
        orders()->place($this->emp, $this->lunchItems[0], $this->me);

        expect(orders()->place($other, $this->lunchItems[0], Actor::employee($other))->priceType->code)->toBe(PriceType::DONATED);
    });
});

describe('cancellation', function () {
    it('lets the employee cancel while the slot is orderable', function () {
        $order = orders()->place($this->emp, $this->lunchItems[0], $this->me);
        $cancelled = orders()->cancel($order, $this->me);

        expect($cancelled->status)->toBe(OrderStatus::Cancelled)
            ->and($cancelled->cancelled_at)->not->toBeNull()
            ->and($cancelled->cancelled_by_user_id)->toBeNull();
    });

    it('does not re-type other orders, and the next order is donated again', function () {
        $donated = orders()->place($this->emp, $this->lunchItems[0], $this->me);
        $extra = orders()->place($this->emp, $this->lunchItems[1], $this->me, acceptExtra: true);

        orders()->cancel($donated, $this->me);

        expect($extra->fresh()->priceType->code)->toBe(PriceType::EXTRA)
            ->and($extra->fresh()->unit_price_cents)->toBe(600);

        $next = orders()->place($this->emp, $this->lunchItems[2], $this->me);
        expect($next->priceType->code)->toBe(PriceType::DONATED);
    });

    it('refuses cancellation after the cutoff with a Slovak message', function () {
        $order = orders()->place($this->emp, $this->lunchItems[0], $this->me);
        travelToLocal('2026-11-10 09:00:00');

        expect(fn () => orders()->cancel($order, $this->me))
            ->toThrow(OrderingException::class, 'Objednávky na obed sú už uzavreté.');
    });

    it('refuses cancelling an already cancelled order', function () {
        $order = orders()->place($this->emp, $this->lunchItems[0], $this->me);
        orders()->cancel($order, $this->me);

        expect(fn () => orders()->cancel($order, $this->me))->toThrow(OrderingException::class, 'Táto objednávka už bola zrušená.');
    });

    it('does not let an employee cancel someone else\'s order', function () {
        $order = orders()->place($this->emp, $this->lunchItems[0], $this->me);
        $other = employee();

        orders()->cancel($order, Actor::employee($other));
    })->throws(AuthorizationException::class);
});

describe('switching option', function () {
    it('switches the option and keeps price type and price', function () {
        $order = orders()->place($this->emp, $this->lunchItems[0], $this->me);
        $switched = orders()->switchItem($order, $this->lunchItems[1], $this->me);

        expect($switched->id)->toBe($order->id)
            ->and($switched->menu_item_id)->toBe($this->lunchItems[1]->id)
            ->and($switched->priceType->code)->toBe(PriceType::DONATED)
            ->and($switched->unit_price_cents)->toBe(150)
            ->and(Order::count())->toBe(1);
    });

    it('refuses switching to an option of another menu', function () {
        $order = orders()->place($this->emp, $this->lunchItems[0], $this->me);

        expect(fn () => orders()->switchItem($order, $this->dinnerItems[0], $this->me))->toThrow(OrderingException::class);
    });

    it('refuses switching after the cutoff', function () {
        $order = orders()->place($this->emp, $this->lunchItems[0], $this->me);
        travelToLocal('2026-11-10 09:00:01');

        expect(fn () => orders()->switchItem($order, $this->lunchItems[1], $this->me))->toThrow(OrderingException::class);
    });
});

describe('ordering window', function () {
    it('refuses orders after the cutoff', function () {
        travelToLocal('2026-11-10 09:00:00');

        expect(fn () => orders()->place($this->emp, $this->lunchItems[0], $this->me))
            ->toThrow(OrderingException::class, 'Objednávky na obed sú už uzavreté.');
        expect(orders()->place($this->emp, $this->dinnerItems[0], $this->me))->toBeInstanceOf(Order::class);
    });

    it('refuses orders when the slot is manually locked', function () {
        app(SlotLockService::class)->lock('2026-11-10', lunch(), User::factory()->kitchen()->create());

        expect(fn () => orders()->place($this->emp, $this->lunchItems[0], $this->me))
            ->toThrow(OrderingException::class, 'Objednávky na obed sú už uzavreté.');
    });

    it('refuses orders on a closed day', function () {
        ClosedDay::create(['date' => '2026-11-10', 'meal_slot_id' => dinner()->id, 'note' => 'Sviatok']);

        expect(fn () => orders()->place($this->emp, $this->dinnerItems[0], $this->me))
            ->toThrow(OrderingException::class, 'V tento deň sa večeru nevydáva. Sviatok');
    });

    it('refuses inactive employees and inactive options', function () {
        $this->lunchItems[0]->update(['is_active' => false]);
        expect(fn () => orders()->place($this->emp, $this->lunchItems[0], $this->me))->toThrow(OrderingException::class, 'Toto jedlo už nie je v ponuke.');

        $this->emp->update(['is_active' => false]);
        expect(fn () => orders()->place($this->emp, $this->lunchItems[1], $this->me))->toThrow(OrderingException::class);
    });
});

describe('staff actions', function () {
    it('lets an admin order on behalf of an employee', function () {
        $admin = admin();
        $order = orders()->place($this->emp, $this->lunchItems[0], Actor::staff($admin));

        expect($order->created_by_user_id)->toBe($admin->id)
            ->and($order->priceType->code)->toBe(PriceType::DONATED);
    });

    it('requires a reason for admin changes after lock', function () {
        $order = orders()->place($this->emp, $this->lunchItems[0], $this->me);
        travelToLocal('2026-11-10 10:00:00');
        $admin = admin();

        expect(fn () => orders()->cancel($order, Actor::staff($admin)))
            ->toThrow(OrderingException::class);
        expect(fn () => orders()->cancel($order, Actor::staff($admin, '   ')))
            ->toThrow(OrderingException::class);

        $cancelled = orders()->cancel($order, Actor::staff($admin, 'Odišiel domov chorý'));
        expect($cancelled->status)->toBe(OrderStatus::Cancelled)
            ->and($cancelled->cancelled_by_user_id)->toBe($admin->id);

        $log = DB::table('activity_log')->where('event', 'cancelled')->first();
        expect(json_decode($log->properties, true)['reason'])->toBe('Odišiel domov chorý')
            ->and($log->causer_id)->toBe($admin->id);
    });

    it('does not let kitchen or payroll users change orders', function (Role $role) {
        $user = User::factory()->withRoles($role)->create();

        orders()->place($this->emp, $this->lunchItems[0], Actor::staff($user, 'x'));
    })->with([Role::Kitchen, Role::Payroll])->throws(AuthorizationException::class);

    it('lets an admin change the price type with a reason and reprices it', function () {
        $order = orders()->place($this->emp, $this->lunchItems[0], $this->me);
        $admin = admin();

        expect(fn () => orders()->changePriceType($order, PriceType::byCode('extra'), Actor::staff($admin)))
            ->toThrow(OrderingException::class);

        $changed = orders()->changePriceType($order, PriceType::byCode('extra'), Actor::staff($admin, 'Druhé jedlo pre rodinu'));
        expect($changed->priceType->code)->toBe('extra')
            ->and($changed->unit_price_cents)->toBe(600)
            ->and(DB::table('activity_log')->where('event', 'price_type_changed')->count())->toBe(1);
    });

    it('never allows two active donated orders, even via admin correction', function () {
        orders()->place($this->emp, $this->lunchItems[0], $this->me);
        $extra = orders()->place($this->emp, $this->lunchItems[1], $this->me, acceptExtra: true);

        expect(fn () => orders()->changePriceType($extra, PriceType::byCode('donated'), Actor::staff(admin(), 'oprava')))
            ->toThrow(OrderingException::class);
    });
});

describe('price versioning and snapshot', function () {
    it('uses the price version valid on the meal date', function () {
        Price::create(['price_type_id' => PriceType::byCode('donated')->id, 'amount_cents' => 200, 'valid_from' => '2026-11-11']);
        $tomorrow = menuItems('2026-11-11', lunch());

        expect(orders()->place($this->emp, $this->lunchItems[0], $this->me)->unit_price_cents)->toBe(150)
            ->and(orders()->place($this->emp, $tomorrow[0], $this->me)->unit_price_cents)->toBe(200);
    });

    it('never changes existing orders when a new price version is added', function () {
        $order = orders()->place($this->emp, $this->lunchItems[0], $this->me);
        Price::create(['price_type_id' => PriceType::byCode('donated')->id, 'amount_cents' => 175, 'valid_from' => '2026-11-01']);

        expect($order->fresh()->unit_price_cents)->toBe(150);
    });
});

describe('database guard', function () {
    it('rejects a second active donated order at the database level', function () {
        $order = orders()->place($this->emp, $this->lunchItems[0], $this->me);

        Order::create([
            ...$order->only(['employee_id', 'meal_slot_id', 'price_type_id', 'unit_price_cents']),
            'date' => '2026-11-10', 'menu_item_id' => $this->lunchItems[1]->id, 'status' => OrderStatus::Active,
        ]);
    })->throws(UniqueConstraintViolationException::class);

    it('allows a new donated order once the previous one is cancelled', function () {
        $order = orders()->place($this->emp, $this->lunchItems[0], $this->me);
        orders()->cancel($order, $this->me);
        orders()->place($this->emp, $this->lunchItems[0], $this->me);

        expect(Order::query()->where('donated_guard', 1)->count())->toBe(1)
            ->and(Order::count())->toBe(2);
    });
});

it('audits every order action', function () {
    $order = orders()->place($this->emp, $this->lunchItems[0], $this->me);
    orders()->switchItem($order, $this->lunchItems[1], $this->me);
    orders()->cancel($order, $this->me);

    $log = DB::table('activity_log')->where('log_name', 'orders')->orderBy('id')->get();
    expect($log->pluck('event')->all())->toBe(['created', 'switched', 'cancelled'])
        ->and($log->pluck('causer_type')->unique()->all())->toBe([Employee::class]);
});
