<?php

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Employee;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\DB;

it('serves the health check and the staff login page', function () {
    $this->get('/up')->assertOk();
    $this->get('/admin/login')->assertOk()->assertSee('Jedáleň');
});

it('seeds consistent demo data', function () {
    travelToLocal('2026-11-10 10:00:00');
    $this->seed(DemoDataSeeder::class);

    expect(Employee::count())->toBe(80)
        ->and(Order::count())->toBeGreaterThan(300);

    $doubleDonated = DB::table('orders')
        ->where('status', OrderStatus::Active->value)->whereNotNull('donated_guard')
        ->select('employee_id', 'date', 'meal_slot_id')
        ->groupBy('employee_id', 'date', 'meal_slot_id')->havingRaw('COUNT(*) > 1')->count();
    expect($doubleDonated)->toBe(0);
});

it('resets a PIN from the command line', function () {
    $employee = employee(['osobne_cislo' => '4321']);

    $this->artisan('app:reset-pin', ['osobne_cislo' => '4321', '--pin' => '5678'])->assertSuccessful();
    expect($employee->fresh()->checkPin('5678'))->toBeTrue();

    $this->artisan('app:reset-pin', ['osobne_cislo' => '4321', '--pin' => '12'])->assertFailed();
});

it('creates a staff user with roles', function () {
    $this->artisan('app:staff-user', ['email' => 'Kuchar@Example.sk', '--name' => 'Kuchár', '--role' => ['kitchen']])
        ->expectsQuestion('Password (min. 12 characters)', 'dlhe-heslo-123')
        ->assertSuccessful();

    $user = User::query()->where('email', 'kuchar@example.sk')->firstOrFail();
    expect($user->hasRole(Role::Kitchen))->toBeTrue()
        ->and($user->isAdmin())->toBeFalse();
});
