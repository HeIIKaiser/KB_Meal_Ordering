<?php

use App\Domain\Attendance\AttendanceCalculator;
use App\Domain\Attendance\MealRule;
use App\Domain\Tickets\TicketAllocation;
use App\Models\AttendanceResult;
use App\Models\TicketMonthCount;
use App\Models\TicketOverride;
use App\Support\Settings;

describe('per-day rule', function () {
    it('counts 0 / 1 / 2 meals at the thresholds and applies the cap', function () {
        $rule = app(MealRule::class);

        expect($rule->meals(0))->toBe(0)
            ->and($rule->meals(hm('4:00')))->toBe(0)
            ->and($rule->meals(hm('4:01')))->toBe(1)
            ->and($rule->meals(hm('9:59')))->toBe(1)
            ->and($rule->meals(hm('10:00')))->toBe(2)
            ->and($rule->meals(hm('14:22')))->toBe(2)
            ->and($rule->meals(hm('14:22'), cap: 1))->toBe(1);
    });

    it('takes the thresholds from the settings', function () {
        app(Settings::class)->set(Settings::ATTENDANCE_ONE_MEAL_SECONDS, hm('3:00'));
        app(Settings::class)->set(Settings::ATTENDANCE_TWO_MEALS_SECONDS, hm('11:00'));

        expect(app(MealRule::class)->meals(hm('3:30')))->toBe(1)
            ->and(app(MealRule::class)->meals(hm('10:30')))->toBe(1);
    });
});

describe('month estimate and final (ticket mode)', function () {
    beforeEach(function () {
        travelToLocal('2026-10-20 12:00:00');
        $this->emp = joinGroup(employee(['osobne_cislo' => '029']), ticketGroup(20, '2026-10-01'), '2026-10-01');
        syncAttendance('2026-10', ['29' => [
            '2026-10-01' => ['12:00', '14:22'],            // past, worked 12 h → 2
            '2026-10-02' => ['8:00', '7:30'],              // past, worked 7:30 → 1
            '2026-10-03' => ['12:00', 0, [2]],             // past, vacation (absence) → 0
            '2026-10-05' => ['12:00', 0],                  // past, planned, nothing filled in → estimate 2, final 0 + flag
            '2026-10-06' => [0, '9:00'],                   // past, extra shift → 1
            '2026-10-07' => ['8:00', '0:00', [1]],         // only "Obed" (code 1) is not an absence… sent filtered by the bridge
            '2026-10-21' => ['12:00', 0],                  // future → planned 2
            '2026-10-22' => ['8:00', 0],                   // future → planned 1
            '2026-10-23' => [0, 0],                        // day off → 0
        ]]);
    });

    it('estimates future = plan, past filled = actual, past unfilled planned = plan, extra shift = actual', function () {
        $e = app(AttendanceCalculator::class)->compute($this->emp, '2026-10', final: false);

        // 2 + 1 + 0 + 2 (unfilled → plan) + 1 + 0 (code 1 is passed as an absence here → filled, 0 worked) + 2 + 1 = 9
        expect($e->tickets)->toBe(9)
            ->and($e->unfilled)->toBe(['2026-10-05'])
            ->and(AttendanceResult::query()->sole()->estimate_meals)->toBe(9)
            ->and(app(TicketAllocation::class)->allocationWithSource($this->emp, '2026-10'))->toBe(['tickets' => 9, 'source' => 'attendance_estimate']);
    });

    it('counts only actual data at finalisation and flags the unfilled planned day', function () {
        travelToLocal('2026-11-11 09:00:00');
        $e = app(AttendanceCalculator::class)->compute($this->emp, '2026-10', final: true);

        // 2 + 1 + 0 + 0 (unfilled) + 1 + 0 + 0 (21.–23. nothing filled in, planned → flagged)
        expect($e->tickets)->toBe(4)
            ->and($e->unfilled)->toBe(['2026-10-05', '2026-10-21', '2026-10-22']);
    });

    it('applies the employee cap everywhere', function () {
        $this->emp->update(['max_meals_per_day' => 1]);
        $e = app(AttendanceCalculator::class)->compute($this->emp->fresh(), '2026-10', final: false);

        expect($e->tickets)->toBe(6); // 1+1+0+1+1+0+1+1
    });
});

describe('allocation chain', function () {
    it('uses manual > attendance_final > import > attendance_estimate > group month > group default', function () {
        travelToLocal('2026-10-20 12:00:00');
        $group = ticketGroup(20, '2026-10-01');
        $emp = joinGroup(employee(['osobne_cislo' => '7']), $group, '2026-10-01');
        $alloc = fn () => app(TicketAllocation::class)->allocationWithSource($emp, '2026-10');

        expect($alloc())->toBe(['tickets' => 20, 'source' => 'group_default']);
        TicketMonthCount::query()->create(['meal_group_id' => $group->id, 'month' => '2026-10', 'tickets' => 18]);
        expect($alloc())->toBe(['tickets' => 18, 'source' => 'group_month']);
        AttendanceResult::query()->create(['employee_id' => $emp->id, 'month' => '2026-10', 'estimate_meals' => 15]);
        expect($alloc())->toBe(['tickets' => 15, 'source' => 'attendance_estimate']);
        $o = TicketOverride::query()->create(['employee_id' => $emp->id, 'month' => '2026-10', 'tickets' => 14, 'source' => 'import']);
        expect($alloc())->toBe(['tickets' => 14, 'source' => 'import']);
        AttendanceResult::query()->where('employee_id', $emp->id)->update(['final_meals' => 13]);
        expect($alloc())->toBe(['tickets' => 13, 'source' => 'attendance_final']);
        $o->update(['source' => 'manual', 'tickets' => 12]);
        expect($alloc())->toBe(['tickets' => 12, 'source' => 'manual']);
    });
});

it('accepts a sync while nobody is in ticket or mandatory mode yet', function () {
    employee(['osobne_cislo' => '5']);

    $r = syncAttendance('2026-10', ['5' => ['2026-10-01' => ['8:00', '8:00']], '6' => ['2026-10-01' => ['8:00', '8:00']]]);
    expect($r['unmatched'])->toBe(['6'])->and($r['days'])->toBe(2);
});
