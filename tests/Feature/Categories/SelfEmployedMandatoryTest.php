<?php

use App\Domain\Attendance\AttendanceRoster;
use App\Domain\Attendance\EmployeeMatcher;
use App\Domain\Employees\EmployeeImport;
use App\Domain\Exports\Invoice\InvoiceData;
use App\Domain\Exports\Payroll\PayrollData;
use App\Domain\Ordering\Actor;
use App\Domain\Ordering\ExtraConfirmationRequired;
use App\Domain\Ordering\OrderService;
use App\Domain\Tickets\MealGroupService;
use App\Enums\PricingMode;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Models\FixedPrice;
use App\Models\MandatoryMeal;
use App\Models\MealGroup;
use Livewire\Livewire;

/*
 * SZČO in mandatory mode (Roman, 2026-11-17): mandatory meals and take-home orders at the
 * person's fixed price when one is set; and a separate attendance number for people whose
 * WebVisitor number differs from osobné číslo.
 */

beforeEach(function () {
    travelToLocal('2026-10-12 07:00:00');
    $this->group = mandatoryGroup(2, '2026-10-01', 'SZČO – kuchyňa');
    $this->group->update(['settlement' => 'invoice']);
    $this->szco = joinGroup(employee(['osobne_cislo' => '0400']), $this->group, '2026-10-01');
});

describe('mandatory mode at a fixed price', function () {
    it('charges mandatory meals and take-home orders at the fixed price, without a dialog, and invoices them', function () {
        FixedPrice::query()->create(['meal_group_id' => $this->group->id, 'amount_cents' => 500, 'valid_from' => '2026-01-01']);

        $order = app(OrderService::class)->place($this->szco, firstItem('2026-10-12'), Actor::employee($this->szco)); // no acceptExtra needed
        syncAttendance('2026-10', ['400' => ['2026-10-12' => ['12:00', '12:00'], '2026-10-13' => ['12:00', '12:00']]]);

        expect($order->unit_price_cents)->toBe(500)
            ->and($order->pricing_mode)->toBe(PricingMode::Mandatory)
            ->and(MandatoryMeal::query()->pluck('unit_price_cents')->unique()->all())->toBe([500])
            ->and((int) MandatoryMeal::query()->sum('count'))->toBe(4);

        $row = InvoiceData::for('2026-10')->rows->sole();
        expect([$row->lunches, $row->other, $row->totalCents])->toBe([1, 4, 2500])
            ->and(PayrollData::for('2026-10')->rows)->toHaveCount(0);
    });

    it('uses a personal fixed price before the group price', function () {
        FixedPrice::query()->create(['meal_group_id' => $this->group->id, 'amount_cents' => 500, 'valid_from' => '2026-01-01']);
        FixedPrice::query()->create(['employee_id' => $this->szco->id, 'amount_cents' => 420, 'valid_from' => '2026-01-01']);
        syncAttendance('2026-10', ['400' => ['2026-10-12' => ['12:00', '12:00']]]);

        expect(MandatoryMeal::query()->sole()->unit_price_cents)->toBe(420);
    });

    it('keeps 1,50 € charges and 6 € take-home with a dialog when no fixed price is set', function () {
        expect(fn () => app(OrderService::class)->place($this->szco, firstItem('2026-10-12'), Actor::employee($this->szco)))
            ->toThrow(ExtraConfirmationRequired::class);
        syncAttendance('2026-10', ['400' => ['2026-10-12' => ['12:00', '12:00']]]);

        expect(MandatoryMeal::query()->sole()->unit_price_cents)->toBe(150);
    });

    it('keeps the snapshot of take-home orders when a fixed price is added later', function () {
        [$order] = orderAll($this->szco, [firstItem('2026-10-12')]);
        FixedPrice::query()->create(['meal_group_id' => $this->group->id, 'amount_cents' => 500, 'valid_from' => '2026-01-01']);
        syncAttendance('2026-10', ['400' => ['2026-10-12' => ['12:00', '12:00']]]); // reprices the month

        expect($order->fresh()->unit_price_cents)->toBe(600);
    });

    it('prices orders at the fixed price when a group switches into mandatory mode', function () {
        $g = MealGroup::query()->create(['name' => 'Prechod', 'settlement' => 'invoice']);
        FixedPrice::query()->create(['meal_group_id' => $g->id, 'amount_cents' => 480, 'valid_from' => '2026-01-01']);
        $emp = joinGroup(employee(), $g, '2026-10-01');
        orderAll($emp, [firstItem('2026-10-14')]);

        app(MealGroupService::class)->switchMode($g, PricingMode::Mandatory, '2026-10-14', adminUser());
        expect(prices($emp))->toBe([480]);
    });
});

describe('attendance number', function () {
    it('pairs WebVisitor data by the attendance number when set', function () {
        $this->szco->update(['attendance_cislo' => '9400']);

        $r = syncAttendance('2026-10', ['9400' => ['2026-10-12' => ['12:00', '12:00']], '400' => ['2026-10-12' => ['8:00', '8:00']]]);

        expect($r['unmatched'])->toBe(['400'])
            ->and((int) MandatoryMeal::query()->where('employee_id', $this->szco->id)->sum('count'))->toBe(2)
            ->and(app(EmployeeMatcher::class)->find('9400')?->id)->toBe($this->szco->id)
            ->and(app(AttendanceRoster::class)->employees('2026-10')->pluck('employee.id')->all())->toContain($this->szco->id);
    });

    it('treats an attendance number equal to another person\'s osobné číslo as a conflict', function () {
        employee(['osobne_cislo' => '0500']);
        $this->szco->update(['attendance_cislo' => '500']);

        $r = syncAttendance('2026-10', ['500' => ['2026-10-12' => ['12:00', '12:00']]]);
        expect($r['unmatched'])->toBe(['500'])->and(MandatoryMeal::query()->count())->toBe(0);
    });

    it('is edited on the employee form (digits, unique) and imported', function () {
        $this->actingAs(adminUser());
        employee(['osobne_cislo' => '0600', 'attendance_cislo' => '77']);

        Livewire::test(EditEmployee::class, ['record' => $this->szco->getRouteKey()])
            ->fillForm(['attendance_cislo' => 'A1'])->call('save')->assertHasFormErrors(['attendance_cislo'])
            ->fillForm(['attendance_cislo' => '77'])->call('save')->assertHasFormErrors(['attendance_cislo' => 'unique'])
            ->fillForm(['attendance_cislo' => '9400'])->call('save')->assertHasNoFormErrors();
        expect($this->szco->fresh()->attendance_cislo)->toBe('9400');

        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, "osobne_cislo;cislo_dochadzka\n0400;9401\n0600;9401\n");
        $preview = app(EmployeeImport::class)->preview($path, 'z.csv');
        expect($preview['rows'][0]['data']['attendance_cislo'])->toBe('9401');
    });
});
