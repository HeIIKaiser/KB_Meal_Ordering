<?php

use App\Domain\Ordering\AvailabilityService;
use App\Domain\Ordering\SlotLockService;
use App\Domain\Ordering\SlotState;
use App\Enums\Role;
use App\Models\ClosedDay;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

function state(string $date, $slot = null): SlotState
{
    return app(AvailabilityService::class)->for($date, $slot ?? lunch())->state;
}

describe('automatic cutoff', function () {
    beforeEach(function () {
        menuItems('2026-11-10', lunch());
        menuItems('2026-11-10', dinner());
    });

    it('is open one second before the cutoff', function () {
        travelToLocal('2026-11-10 08:59:59');
        expect(state('2026-11-10'))->toBe(SlotState::Open);
    });

    it('is closed exactly at the cutoff', function () {
        travelToLocal('2026-11-10 09:00:00');
        expect(state('2026-11-10'))->toBe(SlotState::PastCutoff);
    });

    it('evaluates each slot with its own cutoff', function () {
        travelToLocal('2026-11-10 12:00:00');
        expect(state('2026-11-10', lunch()))->toBe(SlotState::PastCutoff)
            ->and(state('2026-11-10', dinner()))->toBe(SlotState::Open);

        travelToLocal('2026-11-10 14:00:00');
        expect(state('2026-11-10', dinner()))->toBe(SlotState::PastCutoff);
    });

    it('supports a day-before cutoff', function () {
        lunch()->update(['cutoff_days_before' => 1, 'cutoff_time' => '14:00:00']);

        travelToLocal('2026-11-09 13:59:59');
        expect(state('2026-11-10'))->toBe(SlotState::Open);

        travelToLocal('2026-11-09 14:00:00');
        expect(state('2026-11-10'))->toBe(SlotState::PastCutoff);
    });

    it('is closed for past days', function () {
        travelToLocal('2026-11-11 06:00:00');
        expect(state('2026-11-10'))->toBe(SlotState::PastCutoff);
    });

    it('uses the local date, not the UTC date, around midnight', function () {
        menuItems('2026-11-11', lunch());
        // 23:30 UTC on Nov 10 is already 00:30 on Nov 11 in Bratislava.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 23:30:00', 'UTC'));

        expect(state('2026-11-10'))->toBe(SlotState::PastCutoff)
            ->and(state('2026-11-11'))->toBe(SlotState::Open);
    });
});

describe('DST change days', function () {
    it('handles the spring change (CET→CEST) on the cutoff day', function () {
        menuItems('2026-03-29', lunch());
        $cutoff = app(AvailabilityService::class)->cutoffAt('2026-03-29', lunch());
        expect($cutoff->utc()->format('Y-m-d H:i'))->toBe('2026-03-29 07:00'); // 09:00 CEST

        $this->travelTo(CarbonImmutable::parse('2026-03-29 06:59:59', 'UTC'));
        expect(state('2026-03-29'))->toBe(SlotState::Open);

        $this->travelTo(CarbonImmutable::parse('2026-03-29 07:00:00', 'UTC'));
        expect(state('2026-03-29'))->toBe(SlotState::PastCutoff);
    });

    it('handles the autumn change (CEST→CET) on the cutoff day', function () {
        menuItems('2026-10-25', lunch());
        $cutoff = app(AvailabilityService::class)->cutoffAt('2026-10-25', lunch());
        expect($cutoff->utc()->format('Y-m-d H:i'))->toBe('2026-10-25 08:00'); // 09:00 CET

        $this->travelTo(CarbonImmutable::parse('2026-10-25 07:59:59', 'UTC'));
        expect(state('2026-10-25'))->toBe(SlotState::Open);

        $this->travelTo(CarbonImmutable::parse('2026-10-25 08:00:00', 'UTC'));
        expect(state('2026-10-25'))->toBe(SlotState::PastCutoff);
    });

    it('handles a day-before cutoff that falls on a DST change day', function () {
        lunch()->update(['cutoff_days_before' => 1, 'cutoff_time' => '14:00:00']);
        $service = app(AvailabilityService::class);

        expect($service->cutoffAt('2026-03-30', lunch())->utc()->format('Y-m-d H:i'))->toBe('2026-03-29 12:00')
            ->and($service->cutoffAt('2026-10-26', lunch())->utc()->format('Y-m-d H:i'))->toBe('2026-10-25 13:00')
            // the day after the change: back to the normal offset
            ->and($service->cutoffAt('2026-10-27', lunch())->utc()->format('Y-m-d H:i'))->toBe('2026-10-26 13:00');
    });
});

describe('manual lock', function () {
    beforeEach(function () {
        menuItems('2026-11-10');
        travelToLocal('2026-11-10 07:00:00');
    });

    it('locks and unlocks a slot', function () {
        $kitchen = User::factory()->kitchen()->create();
        $locks = app(SlotLockService::class);

        $locks->lock('2026-11-10', lunch(), $kitchen);
        expect(state('2026-11-10'))->toBe(SlotState::Locked)
            ->and(state('2026-11-10', dinner()))->not->toBe(SlotState::Locked);

        $locks->unlock('2026-11-10', lunch(), $kitchen);
        expect(state('2026-11-10'))->toBe(SlotState::Open);
    });

    it('records who locked and audits lock and unlock', function () {
        $admin = User::factory()->admin()->create();
        $lock = app(SlotLockService::class)->lock('2026-11-10', lunch(), $admin);
        app(SlotLockService::class)->lock('2026-11-10', lunch(), $admin); // idempotent
        app(SlotLockService::class)->unlock('2026-11-10', lunch(), $admin);

        expect($lock->locked_by_user_id)->toBe($admin->id)
            ->and(DB::table('activity_log')->where('log_name', 'locks')->pluck('event')->all())->toBe(['locked', 'unlocked']);
    });

    it('may only be used by kitchen or admin', function () {
        $payroll = User::factory()->withRoles(Role::Payroll)->create();
        app(SlotLockService::class)->lock('2026-11-10', lunch(), $payroll);
    })->throws(AuthorizationException::class);
});

describe('closed days', function () {
    beforeEach(function () {
        menuItems('2026-11-17', lunch());
        menuItems('2026-11-17', dinner());
        travelToLocal('2026-11-16 10:00:00');
    });

    it('closes the whole day', function () {
        ClosedDay::create(['date' => '2026-11-17', 'note' => 'Sviatok']);

        expect(state('2026-11-17', lunch()))->toBe(SlotState::Closed)
            ->and(state('2026-11-17', dinner()))->toBe(SlotState::Closed);
    });

    it('closes a single slot', function () {
        ClosedDay::create(['date' => '2026-11-17', 'meal_slot_id' => dinner()->id]);

        expect(state('2026-11-17', lunch()))->toBe(SlotState::Open)
            ->and(state('2026-11-17', dinner()))->toBe(SlotState::Closed);
    });
});

describe('ordering horizon and menu', function () {
    it('allows ordering up to the horizon (default 14 days)', function () {
        travelToLocal('2026-11-10 10:00:00');
        menuItems('2026-11-24');
        menuItems('2026-11-25');

        expect(state('2026-11-24'))->toBe(SlotState::Open)
            ->and(state('2026-11-25'))->toBe(SlotState::BeyondHorizon);

        app(Settings::class)->set(Settings::ORDERING_HORIZON_DAYS, 15);
        expect(state('2026-11-25'))->toBe(SlotState::Open);
    });

    it('is not orderable without a menu or with only inactive options', function () {
        travelToLocal('2026-11-10 07:00:00');
        expect(state('2026-11-10'))->toBe(SlotState::NoMenu);

        $items = menuItems('2026-11-10');
        $items->each->update(['is_active' => false]);
        expect(state('2026-11-10'))->toBe(SlotState::NoMenu);
    });

    it('is not orderable for an inactive slot', function () {
        travelToLocal('2026-11-10 07:00:00');
        menuItems('2026-11-10');
        lunch()->update(['is_active' => false]);

        expect(state('2026-11-10'))->toBe(SlotState::SlotInactive);
    });

    it('evaluates a whole week with a constant number of queries', function () {
        travelToLocal('2026-11-09 07:00:00');
        foreach (range(9, 15) as $d) {
            menuItems("2026-11-$d", lunch());
            menuItems("2026-11-$d", dinner());
        }

        DB::enableQueryLog();
        $week = app(AvailabilityService::class)->forPeriod('2026-11-09', '2026-11-15');
        $queries = count(DB::getQueryLog());

        expect($week)->toHaveCount(7)
            ->and($week['2026-11-12'][lunch()->id]->state)->toBe(SlotState::Open)
            ->and($queries)->toBeLessThanOrEqual(5);
    });
});
