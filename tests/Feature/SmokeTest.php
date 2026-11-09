<?php

use App\Domain\Employees\PinIssuer;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Employee;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
    expect($doubleDonated)->toBe(0)
        ->and(User::query()->count())->toBe(0)                                  // no well-known staff logins
        ->and(Employee::query()->get()->filter(fn (Employee $e) => $e->printablePin() === '1234'))->toBeEmpty();
});

it('refuses to seed demo data in production', function () {
    app()->instance('env', 'production');

    expect(fn () => app(DemoDataSeeder::class)->run())->toThrow(RuntimeException::class, 'production');
});

it('disables the old demo logins on existing installations', function () {
    $migration = require database_path('migrations/2026_11_10_000500_remove_demo_logins.php');
    $demoAdmin = User::factory()->admin()->create(['email' => 'admin@jedalen.test']);
    $realAdmin = User::factory()->admin()->create(['email' => 'roman@example.sk']);
    $demo = employee();
    $demo->setPin('1234', Employee::PIN_BY_SYSTEM);
    $demo->save();
    $mine = employee();
    $mine->setPin('1234', Employee::PIN_BY_EMPLOYEE);                     // chosen by the employee: left alone
    $mine->save();
    $other = employee();
    $other->setPin('480715', Employee::PIN_BY_ADMIN);
    $other->save();

    $migration->up();

    expect($demoAdmin->fresh()->is_active)->toBeFalse()
        ->and(Hash::check('heslo1234', $demoAdmin->fresh()->password))->toBeFalse()
        ->and($realAdmin->fresh()->is_active)->toBeTrue()
        ->and($demo->fresh()->printablePin())->not->toBe('1234')->toHaveLength(PinIssuer::LENGTH)
        ->and($mine->fresh()->checkPin('1234'))->toBeTrue()
        ->and($other->fresh()->printablePin())->toBe('480715');
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

it('does not force staff to set up 2FA', function () {
    $this->actingAs(User::factory()->kitchen()->create())
        ->get('/admin')
        ->assertOk();
});

it('denies the panel to users without a staff role', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertForbidden();
});
