<?php

use App\Domain\Attendance\AttendanceSync;
use App\Domain\Attendance\Finalizer;
use App\Domain\Attendance\MonthTotals;
use App\Domain\Ordering\Actor;
use App\Domain\Ordering\ExtraConfirmationRequired;
use App\Domain\Ordering\OrderingException;
use App\Domain\Ordering\OrderService;
use App\Domain\Tickets\ClosedMonths;
use App\Domain\Tickets\TicketAdmin;
use App\Domain\Tickets\TicketAllocation;
use App\Enums\PricingMode;
use App\Models\AttendanceDay;
use App\Models\AttendanceResult;
use App\Models\AttendanceSync as SyncLog;
use App\Models\MandatoryMeal;
use App\Models\TicketOverride;

beforeEach(function () {
    travelToLocal('2026-11-05 07:00:00');
    $this->admin = adminUser();
});

describe('sync', function () {
    it('replaces the month idempotently, merges duplicates and lists unknown numbers', function () {
        employee(['osobne_cislo' => '003']);
        $people = ['3' => ['2026-10-01' => ['8:00', '8:00'], '2026-10-02' => ['8:00', '8:00']], '999' => ['2026-10-01' => ['8:00', '8:00']]];

        $r = syncAttendance('2026-10', $people);
        syncAttendance('2026-10', $people);
        expect(AttendanceDay::query()->count())->toBe(3)
            ->and($r['unmatched'])->toBe(['999'])
            ->and(SyncLog::query()->where('kind', 'data')->count())->toBe(2);

        syncAttendance('2026-10', ['3' => ['2026-10-01' => ['8:00', '8:00']]]); // replaces the whole month
        expect(AttendanceDay::query()->count())->toBe(1);

        $payload = attendancePayload('2026-10', ['3' => ['2026-10-01' => ['8:00', '3:00']]]);
        $payload['employees'][] = attendancePayload('2026-10', ['003' => ['2026-10-01' => [0, '2:00']]])['employees'][0];
        app(AttendanceSync::class)->ingest($payload);
        expect(AttendanceDay::query()->sole()->total_seconds)->toBe(hm('5:00'));
    });

    it('rejects malformed payloads', function () {
        expect(fn () => app(AttendanceSync::class)->ingest(['month' => '2026-13', 'employees' => []]))->toThrow(InvalidArgumentException::class)
            ->and(fn () => app(AttendanceSync::class)->ingest(attendancePayload('2026-10', ['1' => ['2026-11-01' => ['8:00', '8:00']]])))
            ->toThrow(InvalidArgumentException::class, 'dátum mimo mesiaca');
    });

    it('reprices ticket orders from the live estimate', function () {
        travelToLocal('2026-11-02 07:00:00');
        $emp = joinGroup(employee(['osobne_cislo' => '0101']), ticketGroup(20, '2026-11-01'), '2026-11-01');
        orderAll($emp, [firstItem('2026-11-02'), firstItem('2026-11-03'), firstItem('2026-11-04')]);
        expect(prices($emp))->toBe([150, 150, 150]);

        // Plan: 2.–3. 8 h shifts (1 meal each), 4. day off → 2 tickets.
        syncAttendance('2026-11', ['101' => ['2026-11-02' => ['8:00', 0], '2026-11-03' => ['8:00', 0], '2026-11-04' => [0, 0]]]);

        expect(prices($emp))->toBe([150, 150, 600])
            ->and(app(TicketAllocation::class)->allocationWithSource($emp, '2026-11'))->toBe(['tickets' => 2, 'source' => 'attendance_estimate']);
    });

    it('changes nothing in a closed month and records the difference', function () {
        travelToLocal('2026-10-10 07:00:00');
        $emp = joinGroup(employee(['osobne_cislo' => '0101']), ticketGroup(20, '2026-10-01'), '2026-10-01');
        orderAll($emp, [firstItem('2026-10-12'), firstItem('2026-10-13')]);
        travelToLocal('2026-11-12 08:00:00');
        syncAttendance('2026-10', ['101' => ['2026-10-12' => ['12:00', '12:00'], '2026-10-13' => ['12:00', '12:00']]]);
        app(Finalizer::class)->finalize('2026-10', $this->admin);
        app(ClosedMonths::class)->close('2026-10', $this->admin);
        $before = [prices($emp), AttendanceResult::query()->sole()->final_meals];

        $r = syncAttendance('2026-10', ['101' => ['2026-10-12' => ['12:00', '12:00'], '2026-10-13' => ['12:00', 0, [6]]]]);

        expect([prices($emp), AttendanceResult::query()->sole()->final_meals])->toBe($before)
            ->and($r['closed'])->toBeTrue()
            ->and($r['late_changes'][0])->toMatchArray(['osobne_cislo' => '0101', 'tickets' => [4, 2], 'cents' => 0])
            ->and(SyncLog::query()->latest('id')->first()->late_changes)->toHaveCount(1);
    });

    it('marks the request pending until the requested months arrive', function () {
        $sync = app(AttendanceSync::class);
        expect($sync->pending()['pending'])->toBeFalse();

        $sync->request(['2026-10', '2026-11']);
        expect($sync->pending())->toMatchArray(['pending' => true, 'months' => ['2026-10', '2026-11']]);

        syncAttendance('2026-10', []);
        expect($sync->pending()['pending'])->toBeTrue();
        syncAttendance('2026-11', []);
        expect($sync->pending()['pending'])->toBeFalse();
    });
});

describe('mandatory mode', function () {
    beforeEach(function () {
        $this->emp = joinGroup(employee(['osobne_cislo' => '0029']), mandatoryGroup(2, '2026-10-01'), '2026-10-01');
    });

    it('charges N meals per worked day at the employee price and replaces the charges on re-sync', function () {
        syncAttendance('2026-10', ['29' => [
            '2026-10-01' => ['12:00', '14:22'],  // worked → 2
            '2026-10-02' => ['8:00', '7:00'],    // worked (1 meal by the rule) → still N = 2
            '2026-10-03' => ['12:00', 0, [2]],   // vacation → 0
            '2026-10-04' => [0, 0],              // day off → 0
        ]]);

        expect(MandatoryMeal::query()->orderBy('date')->get()->map(fn ($m) => [$m->date->toDateString(), $m->count, $m->unit_price_cents, $m->source])->all())
            ->toBe([['2026-10-01', 2, 150, 'estimate'], ['2026-10-02', 2, 150, 'estimate']])
            ->and(MonthTotals::cents($this->emp->id, '2026-10'))->toBe(600);

        syncAttendance('2026-10', ['29' => ['2026-10-01' => ['12:00', '14:22']]]);
        expect(MandatoryMeal::query()->count())->toBe(1);
    });

    it('respects the employee cap ("len obedy")', function () {
        $this->emp->update(['max_meals_per_day' => 1]);
        syncAttendance('2026-10', ['29' => ['2026-10-01' => ['12:00', '14:22']]]);

        expect(MandatoryMeal::query()->sole()->count)->toBe(1);
    });

    it('prices app orders always at 6 € (take-home), with a confirmation', function () {
        travelToLocal('2026-10-01 07:00:00');
        $item = firstItem('2026-10-01');

        expect(fn () => app(OrderService::class)->place($this->emp, $item, Actor::employee($this->emp)))
            ->toThrow(ExtraConfirmationRequired::class, 'Povinná strava sa účtuje automaticky podľa dochádzky');

        $order = app(OrderService::class)->place($this->emp, $item, Actor::employee($this->emp), acceptExtra: true);
        expect($order->unit_price_cents)->toBe(600)->and($order->pricing_mode)->toBe(PricingMode::Mandatory);
    });
});

describe('finalisation', function () {
    beforeEach(function () {
        travelToLocal('2026-10-10 07:00:00');
        $this->group = ticketGroup(20, '2026-10-01');
        $this->emp = joinGroup(employee(['osobne_cislo' => '0101', 'last_name' => 'Anna']), $this->group, '2026-10-01');
        $this->other = joinGroup(employee(['osobne_cislo' => '0102', 'last_name' => 'Boris']), $this->group, '2026-10-01');
        $i = lunchItemsFor(['2026-10-12', '2026-10-13', '2026-10-14']);
        orderAll($this->emp, array_values($i));
        orderAll($this->other, [$i['2026-10-12']]);
        // Plan 3 × 8 h; on the 14th nothing is filled in.
        syncAttendance('2026-10', [
            '101' => ['2026-10-12' => ['8:00', '8:00'], '2026-10-13' => ['8:00', '8:00'], '2026-10-14' => ['8:00', 0], 'locked' => false],
            '102' => ['2026-10-12' => ['8:00', '8:00'], 'locked' => true],
        ]);
        travelToLocal('2026-11-11 08:00:00');
    });

    it('previews exactly what saving does: unfilled planned day = 0 and flagged', function () {
        expect(prices($this->emp))->toBe([150, 150, 150]); // estimate: unfilled planned day still counts

        $preview = collect(app(Finalizer::class)->preview('2026-10', $this->admin))->keyBy('osobne_cislo');
        expect(prices($this->emp))->toBe([150, 150, 150]) // nothing saved
            ->and($preview['0101'])->toMatchArray(['tickets' => [3, 2], 'used' => 3, 'repriced' => 1, 'cents' => [450, 900], 'changed' => true])
            ->and($preview['0101']['flags'])->toMatchArray(['unfilled' => ['2026-10-14'], 'not_locked' => true])
            ->and($preview['0102']['changed'])->toBeFalse();

        $saved = collect(app(Finalizer::class)->finalize('2026-10', $this->admin))->keyBy('osobne_cislo');
        expect($saved->map(fn ($r) => [$r['tickets'], $r['cents']])->all())->toBe($preview->map(fn ($r) => [$r['tickets'], $r['cents']])->all())
            ->and(prices($this->emp))->toBe([150, 150, 600])
            ->and(AttendanceResult::query()->where('employee_id', $this->emp->id)->value('final_meals'))->toBe(2);
    });

    it('keeps manual overrides and ignores a later import', function () {
        app(TicketAdmin::class)->save('2026-10', [], [$this->emp->id => 3], $this->admin);
        app(Finalizer::class)->finalize('2026-10', $this->admin);
        expect(prices($this->emp))->toBe([150, 150, 150]);

        TicketOverride::query()->where('employee_id', $this->emp->id)->delete();
        TicketOverride::query()->create(['employee_id' => $this->emp->id, 'month' => '2026-10', 'tickets' => 3, 'source' => 'import']);
        expect(app(TicketAllocation::class)->allocationWithSource($this->emp, '2026-10'))->toBe(['tickets' => 2, 'source' => 'attendance_final']);
    });

    it('is blocked in a closed month', function () {
        app(ClosedMonths::class)->close('2026-10', $this->admin);

        expect(fn () => app(Finalizer::class)->finalize('2026-10', $this->admin))->toThrow(OrderingException::class, 'uzavretý');
    });
});
