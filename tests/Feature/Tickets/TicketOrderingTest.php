<?php

use App\Domain\Ordering\Actor;
use App\Domain\Ordering\ExtraConfirmationRequired;
use App\Domain\Ordering\OrderingException;
use App\Domain\Ordering\OrderService;
use App\Domain\Tickets\TicketAdmin;
use App\Domain\Tickets\TicketAllocation;
use App\Enums\PricingMode;
use App\Models\Order;
use App\Models\PriceType;
use App\Models\TicketMonthCount;
use App\Models\TicketOverride;
use App\Support\Settings;
use Spatie\Activitylog\Models\Activity;

/*
 * Ticket mode (lístky): allocation, ordering and the repricing rule.
 * "Now" is Tue 10 Nov 2026 07:00; lunch cutoff 09:00 and dinner 14:00 the same day.
 */

beforeEach(function () {
    travelToLocal('2026-11-10 07:00:00');
    $this->group = ticketGroup(20);
    $this->emp = joinGroup(employee(), $this->group);
    $this->me = Actor::employee($this->emp);
});

/** 19 meals: lunch 10.–19. 11. and dinner 10.–18. 11. (the latest is lunch on the 19th). */
function nineteenMeals(): array
{
    $items = [];
    for ($d = 10; $d <= 19; $d++) {
        $items[] = firstItem("2026-11-$d", lunch());
        if ($d <= 18) {
            $items[] = firstItem("2026-11-$d", dinner());
        }
    }

    return $items;
}

describe('allocation', function () {
    it('falls back from override to the month count to the group default, also for next month', function () {
        $alloc = app(TicketAllocation::class);

        expect($alloc->allocation($this->emp, '2026-11'))->toBe(20)   // group default
            ->and($alloc->allocation($this->emp, '2026-12'))->toBe(20); // next month: no admin action needed

        TicketMonthCount::query()->create(['meal_group_id' => $this->group->id, 'month' => '2026-11', 'tickets' => 21]);
        expect($alloc->allocation($this->emp, '2026-11'))->toBe(21)
            ->and($alloc->allocation($this->emp, '2026-12'))->toBe(20);

        TicketOverride::query()->create(['employee_id' => $this->emp->id, 'month' => '2026-11', 'tickets' => 5]);
        expect($alloc->allocation($this->emp, '2026-11'))->toBe(5);
    });

    it('has no allocation in months without ticket days and for employees without a group', function () {
        $alloc = app(TicketAllocation::class);

        expect($alloc->allocation($this->emp, '2026-10'))->toBeNull()
            ->and($alloc->allocation(employee(), '2026-11'))->toBeNull();
    });
});

describe('ordering', function () {
    it('costs 1,50 € while tickets last, without any daily limit', function () {
        $items = menuItems('2026-11-10', lunch(), 3);
        $orders = orderAll($this->emp, [$items[0], $items[1], $items[2], $items[0]]);

        expect(collect($orders)->map(fn ($o) => $o->priceType->code)->unique()->all())->toBe([PriceType::DONATED])
            ->and(collect($orders)->every(fn ($o) => $o->pricing_mode === PricingMode::Tickets && $o->unit_price_cents === 150))->toBeTrue()
            ->and(Order::query()->whereNotNull('donated_guard')->count())->toBe(0);
    });

    it('asks to confirm 6 € once the month\'s tickets are used up', function () {
        TicketOverride::query()->create(['employee_id' => $this->emp->id, 'month' => '2026-11', 'tickets' => 1]);
        $items = menuItems('2026-11-10', lunch(), 2);
        orderAll($this->emp, [$items[0]]);

        expect(fn () => app(OrderService::class)->place($this->emp, $items[1], $this->me))
            ->toThrow(ExtraConfirmationRequired::class, 'Lístky na november sú minuté. Toto jedlo bude za 6,00 €.');

        $quote = app(OrderService::class)->quote($this->emp, '2026-11-10', lunch());
        expect($quote->isExtra())->toBeTrue()->and($quote->ticketsLeft)->toBe(0);
    });

    it('ignores the extra cap of daily mode', function () {
        app(Settings::class)->set(Settings::MAX_EXTRA_PER_SLOT, 0);
        TicketOverride::query()->create(['employee_id' => $this->emp->id, 'month' => '2026-11', 'tickets' => 0]);

        $order = app(OrderService::class)->place($this->emp, firstItem('2026-11-10'), $this->me, acceptExtra: true);
        expect($order->unit_price_cents)->toBe(600);
    });

    it('does not allow admin price-type corrections on ticket orders', function () {
        [$order] = orderAll($this->emp, [firstItem('2026-11-10')]);

        expect(fn () => app(OrderService::class)->changePriceType($order, PriceType::byCode(PriceType::EXTRA), Actor::staff(adminUser(), 'test')))
            ->toThrow(OrderingException::class, 'V režime lístkov');
    });
});

describe('repricing', function () {
    it('makes the latest meal 6 € with 18 tickets and 19 meals; cancelling any one gives the ticket back', function () {
        TicketOverride::query()->create(['employee_id' => $this->emp->id, 'month' => '2026-11', 'tickets' => 18]);
        $orders = orderAll($this->emp, nineteenMeals());

        expect(prices($this->emp))->toBe([...array_fill(0, 18, 150), 600])
            ->and(end($orders)->fresh()->unit_price_cents)->toBe(600);

        $cancelled = app(OrderService::class)->cancel($orders[5], $this->me);
        expect(prices($this->emp))->toBe(array_fill(0, 18, 150))
            ->and($cancelled->getRelation('promoted')->id)->toBe(end($orders)->id);

        $log = Activity::query()->where('event', 'repriced')->latest('id')->first();
        expect($log->properties['cause'])->toBe('cancel')
            ->and($log->properties['old']['unit_price_cents'])->toBe(600)
            ->and($log->properties['attributes']['unit_price_cents'])->toBe(150);
    });

    it('makes the latest meal the 6 € one when an earlier date is ordered after tickets ran out', function () {
        TicketOverride::query()->create(['employee_id' => $this->emp->id, 'month' => '2026-11', 'tickets' => 2]);
        [$a, $b] = orderAll($this->emp, [firstItem('2026-11-12'), firstItem('2026-11-13')]);

        $quote = app(OrderService::class)->quote($this->emp, '2026-11-11', lunch());
        expect($quote->unitPriceCents)->toBe(600); // marginal cost

        $new = app(OrderService::class)->place($this->emp, firstItem('2026-11-11'), $this->me, acceptExtra: true);
        expect($new->unit_price_cents)->toBe(150)
            ->and($b->fresh()->unit_price_cents)->toBe(600)
            ->and($new->getRelation('repriced')->pluck('id')->all())->toBe([$b->id]);
    });

    it('reprices the latest meals when the allocation is lowered and the earliest 6 € ones when raised', function () {
        TicketOverride::query()->create(['employee_id' => $this->emp->id, 'month' => '2026-11', 'tickets' => 18]);
        orderAll($this->emp, nineteenMeals());
        $admin = adminUser();

        $result = app(TicketAdmin::class)->save('2026-11', [], [$this->emp->id => 15], $admin);
        expect(prices($this->emp))->toBe([...array_fill(0, 15, 150), ...array_fill(0, 4, 600)])
            ->and($result->orders())->toBe(3)
            ->and($result->centsDiff())->toBe(3 * 450);

        app(TicketAdmin::class)->save('2026-11', [], [$this->emp->id => 17], $admin);
        expect(prices($this->emp))->toBe([...array_fill(0, 17, 150), 600, 600]);

        // Removing the override → the group count (20) applies.
        app(TicketAdmin::class)->save('2026-11', [], [$this->emp->id => null], $admin);
        expect(prices($this->emp))->toBe(array_fill(0, 19, 150));

        // A group count for the month applies to everyone in the group.
        app(TicketAdmin::class)->save('2026-11', [$this->group->id => 10], [], $admin);
        expect(prices($this->emp))->toBe([...array_fill(0, 10, 150), ...array_fill(0, 9, 600)]);
    });

    it('writes and audits only the orders that actually change', function () {
        orderAll($this->emp, [firstItem('2026-11-10'), firstItem('2026-11-11'), firstItem('2026-11-12')]);
        expect(Activity::query()->where('event', 'repriced')->count())->toBe(0);

        app(TicketAdmin::class)->save('2026-11', [], [$this->emp->id => 2], adminUser());
        expect(Activity::query()->where('event', 'repriced')->count())->toBe(1);
    });

    it('preview and save agree', function () {
        orderAll($this->emp, nineteenMeals());
        $admin = adminUser();

        $preview = app(TicketAdmin::class)->preview('2026-11', [$this->group->id => 12], [], $admin);
        expect(prices($this->emp))->toBe(array_fill(0, 19, 150)); // nothing saved

        $saved = app(TicketAdmin::class)->save('2026-11', [$this->group->id => 12], [], $admin);
        expect([$preview->orders(), $preview->employees(), $preview->centsDiff()])->toBe([$saved->orders(), $saved->employees(), $saved->centsDiff()])
            ->and($saved->orders())->toBe(7);
    });
});

describe('month boundary', function () {
    it('uses a December ticket for a 2 December meal ordered on 30 November; tickets do not carry over', function () {
        travelToLocal('2026-11-30 07:00:00');
        TicketOverride::query()->create(['employee_id' => $this->emp->id, 'month' => '2026-11', 'tickets' => 5]);
        TicketOverride::query()->create(['employee_id' => $this->emp->id, 'month' => '2026-12', 'tickets' => 1]);

        orderAll($this->emp, [firstItem('2026-11-30')]);                       // November: 4 left (they expire)
        [$dec] = orderAll($this->emp, [firstItem('2026-12-02')]);
        $second = app(OrderService::class)->quote($this->emp, '2026-12-03', lunch());

        $alloc = app(TicketAllocation::class);
        expect($dec->unit_price_cents)->toBe(150)
            ->and($second->unitPriceCents)->toBe(600)
            ->and($alloc->summary($this->emp, '2026-11')['left'])->toBe(4)
            ->and($alloc->summary($this->emp, '2026-12')['used'])->toBe(1);
    });
});
