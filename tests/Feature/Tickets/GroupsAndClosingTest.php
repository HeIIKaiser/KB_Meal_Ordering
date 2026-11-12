<?php

use App\Domain\Ordering\Actor;
use App\Domain\Ordering\OrderingException;
use App\Domain\Ordering\OrderService;
use App\Domain\Tickets\ClosedMonths;
use App\Domain\Tickets\MealGroupService;
use App\Domain\Tickets\TicketAdmin;
use App\Enums\PricingMode;
use App\Enums\Role;
use App\Models\MealGroup;
use App\Models\Order;
use App\Models\PriceType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    travelToLocal('2026-11-10 07:00:00');
    $this->admin = adminUser();
});

describe('group mode switch', function () {
    it('reprices meals from the effective date only, and the preview matches the result', function () {
        $group = MealGroup::query()->create(['name' => '8 h', 'default_tickets' => 3, 'is_active' => true]);
        $emp = joinGroup(employee(), $group);
        // Daily mode: lunch + 2nd lunch (extra) on 10th, 12th, 13th
        foreach (['2026-11-10', '2026-11-12', '2026-11-13'] as $d) {
            $items = menuItems($d, lunch(), 2);
            orderAll($emp, [$items[0], $items[1]]);
        }
        expect(prices($emp))->toBe([150, 600, 150, 600, 150, 600]);

        $service = app(MealGroupService::class);
        $preview = $service->previewSwitch($group, PricingMode::Tickets, '2026-11-12', $this->admin);
        expect(prices($emp))->toBe([150, 600, 150, 600, 150, 600]);

        $result = $service->switchMode($group, PricingMode::Tickets, '2026-11-12', $this->admin);

        // 10th keeps daily pricing; 12th–13th: 4 meals on 3 tickets → the latest is 6 €.
        expect(prices($emp))->toBe([150, 600, 150, 150, 150, 600])
            ->and([$preview->orders(), $preview->employees(), $preview->centsDiff()])->toBe([$result->orders(), $result->employees(), $result->centsDiff()])
            ->and($result->centsDiff())->toBe(-450)
            ->and(Order::query()->where('pricing_mode', 'tickets')->count())->toBe(4)
            ->and(Order::query()->whereNotNull('donated_guard')->pluck('date')->map->toDateString()->all())->toBe(['2026-11-10'])
            ->and(Activity::query()->where('event', 'repriced')->first()->properties['cause'])->toBe('mode_switch');

        // …and back to daily from the 13th: the 13th is re-typed by the daily rule, the guard is consistent again.
        $service->switchMode($group, PricingMode::Daily, '2026-11-13', $this->admin);
        expect(prices($emp))->toBe([150, 600, 150, 150, 150, 600])
            ->and(Order::query()->where('date', '2026-11-13')->pluck('pricing_mode')->unique()->all())->toBe([PricingMode::Daily])
            ->and(Order::query()->whereNotNull('donated_guard')->count())->toBe(2);
    });

    it('lets ordering continue under the new mode', function () {
        $group = MealGroup::query()->create(['name' => '8 h', 'default_tickets' => 3, 'is_active' => true]);
        $emp = joinGroup(employee(), $group);
        app(MealGroupService::class)->switchMode($group, PricingMode::Tickets, '2026-11-10', $this->admin);

        $items = menuItems('2026-11-10', lunch(), 2);
        expect(collect(orderAll($emp, [$items[0], $items[1]]))->pluck('unit_price_cents')->all())->toBe([150, 150]);
    });
});

describe('group change of an employee', function () {
    it('does not reprice this month when the change applies from the 1st of next month', function () {
        $group = ticketGroup(1);
        $emp = employee();
        $items = menuItems('2026-11-10', lunch(), 2);
        orderAll($emp, [$items[0], $items[1]]);

        $result = app(MealGroupService::class)->assign($emp, $group->id, '2026-12-01', $this->admin);

        expect($result->isEmpty())->toBeTrue()
            ->and(prices($emp))->toBe([150, 600])
            ->and(Activity::query()->where('event', 'group_changed')->count())->toBe(1);
    });

    it('reprices with cause group_change when the date is in the past, and the preview says so first', function () {
        $group = ticketGroup(5);
        $emp = employee();
        $items = menuItems('2026-11-10', lunch(), 2);
        orderAll($emp, [$items[0], $items[1]]);

        $preview = app(MealGroupService::class)->previewAssign($emp, $group->id, '2026-11-01', $this->admin);
        expect($preview->orders())->toBe(2)->and($preview->centsDiff())->toBe(-450)->and(prices($emp))->toBe([150, 600]);

        app(MealGroupService::class)->assign($emp, $group->id, '2026-11-01', $this->admin);
        expect(prices($emp))->toBe([150, 150]);
    });

    it('is a no-op when nothing changes', function () {
        $group = ticketGroup(5);
        $emp = joinGroup(employee(), $group);

        app(MealGroupService::class)->assign($emp, $group->id, '2026-12-01', $this->admin);
        expect(Activity::query()->where('event', 'group_changed')->count())->toBe(0);
    });
});

describe('closed months', function () {
    beforeEach(function () {
        $this->group = ticketGroup(5);
        $this->emp = joinGroup(employee(), $this->group);
        [$this->order] = orderAll($this->emp, [firstItem('2026-11-10')]);
        travelToLocal('2026-12-02 10:00:00');
        app(ClosedMonths::class)->close('2026-11', $this->admin);
    });

    it('blocks orders, cancellations, corrections, allocations and mode switches in the month', function () {
        $staff = Actor::staff($this->admin, 'oprava');
        $item = firstItem('2026-11-20');
        $closed = 'Mesiac november 2026 je uzavretý';

        expect(fn () => app(OrderService::class)->place($this->emp, $item, $staff, acceptExtra: true))->toThrow(OrderingException::class, $closed)
            ->and(fn () => app(OrderService::class)->cancel($this->order, $staff))->toThrow(OrderingException::class, $closed)
            ->and(fn () => app(TicketAdmin::class)->save('2026-11', [], [$this->emp->id => 9], $this->admin))->toThrow(OrderingException::class, $closed)
            ->and(fn () => app(MealGroupService::class)->switchMode($this->group, PricingMode::Daily, '2026-11-15', $this->admin))->toThrow(OrderingException::class, $closed)
            ->and(fn () => app(MealGroupService::class)->assign(employee(), $this->group->id, '2026-11-15', $this->admin))->toThrow(OrderingException::class, $closed);

        // December is still open.
        expect(app(TicketAdmin::class)->save('2026-12', [], [$this->emp->id => 9], $this->admin)->isEmpty())->toBeTrue();
    });

    it('blocks daily-mode price corrections too', function () {
        travelToLocal('2026-11-10 07:00:00');
        app(ClosedMonths::class)->reopen('2026-11', $this->admin, 'test');
        [$daily] = orderAll($emp = employee(), [firstItem('2026-11-11')]);
        app(ClosedMonths::class)->close('2026-11', $this->admin);

        expect(fn () => app(OrderService::class)->changePriceType($daily, PriceType::byCode('extra'), Actor::staff($this->admin, 'x')))
            ->toThrow(OrderingException::class, 'uzavretý');
    });

    it('reopens only with a reason, audited', function () {
        expect(fn () => app(ClosedMonths::class)->reopen('2026-11', $this->admin, '  '))->toThrow(OrderingException::class, 'povinný dôvod');

        app(ClosedMonths::class)->reopen('2026-11', $this->admin, 'oprava dochádzky');
        $log = Activity::query()->where('event', 'month_reopened')->sole();
        expect(app(ClosedMonths::class)->isClosed('2026-11'))->toBeFalse()
            ->and($log->properties['reason'])->toBe('oprava dochádzky')
            ->and(Activity::query()->where('event', 'month_closed')->count())->toBe(1);
    });

    it('lets only admins close and reopen', function () {
        expect(fn () => app(ClosedMonths::class)->close('2026-10', User::factory()->withRoles(Role::Payroll)->create()))
            ->toThrow(AuthorizationException::class);
    });
});
