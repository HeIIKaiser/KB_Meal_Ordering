<?php

use App\Enums\Role;
use App\Filament\Pages\OrderingSettings;
use App\Filament\Resources\ClosedDays\Pages\ManageClosedDays;
use App\Filament\Resources\MealSlots\Pages\ManageMealSlots;
use App\Filament\Resources\Prices\Pages\ManagePrices;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\ClosedDay;
use App\Models\Price;
use App\Models\PriceType;
use App\Models\User;
use App\Support\Settings;
use Livewire\Livewire;

beforeEach(function () {
    travelToLocal('2026-11-10 10:00:00');
    $this->admin = User::factory()->admin()->create();
});

it('renders every admin page', function (string $url) {
    $this->actingAs($this->admin)->get($url)->assertOk();
})->with(['/admin', '/admin/employees', '/admin/employees/create', '/admin/employees/import', '/admin/prices', '/admin/meal-slots', '/admin/closed-days', '/admin/users', '/admin/settings', '/admin/profile']);

it('limits pages by role', function (Role $role, array $ok, array $forbidden) {
    $user = User::factory()->withRoles($role)->create();
    foreach ($ok as $url) {
        $this->actingAs($user)->get($url)->assertOk();
    }
    foreach ($forbidden as $url) {
        $this->actingAs($user)->get($url)->assertForbidden();
    }
})->with([
    'kitchen' => [Role::Kitchen, ['/admin', '/admin/closed-days'], ['/admin/prices', '/admin/meal-slots', '/admin/users', '/admin/settings', '/admin/employees']],
    'payroll' => [Role::Payroll, ['/admin', '/admin/prices'], ['/admin/closed-days', '/admin/meal-slots', '/admin/users', '/admin/settings', '/admin/employees']],
]);

describe('prices', function () {
    it('shows the current prices', function () {
        $this->actingAs($this->admin)->get('/admin/prices')->assertSee('Aktuálne: Darované 1,50 € · Extra 6,00 €');
    });

    it('adds a future price version entered in euros', function () {
        $this->actingAs($this->admin);

        Livewire::test(ManagePrices::class)
            ->callAction('create', ['price_type_id' => PriceType::byCode('donated')->id, 'amount_cents' => '1,80', 'valid_from' => '2027-01-01'])
            ->assertHasNoActionErrors();

        $p = Price::query()->where('valid_from', '2027-01-01')->sole();
        expect($p->amount_cents)->toBe(180)->and($p->created_by_user_id)->toBe($this->admin->id);
    });

    it('rejects invalid amounts, past dates and duplicate dates', function () {
        $this->actingAs($this->admin);
        $donated = PriceType::byCode('donated')->id;

        Livewire::test(ManagePrices::class)
            ->callAction('create', ['price_type_id' => $donated, 'amount_cents' => 'abc', 'valid_from' => '2027-01-01'])
            ->assertHasActionErrors(['amount_cents']);
        Livewire::test(ManagePrices::class)
            ->callAction('create', ['price_type_id' => $donated, 'amount_cents' => '2', 'valid_from' => '2026-11-01'])
            ->assertHasActionErrors(['valid_from']);
        Price::create(['price_type_id' => $donated, 'amount_cents' => 200, 'valid_from' => '2027-01-01']);
        Livewire::test(ManagePrices::class)
            ->callAction('create', ['price_type_id' => $donated, 'amount_cents' => '2', 'valid_from' => '2027-01-01'])
            ->assertHasActionErrors(['valid_from']);
    });

    it('does not allow editing or deleting a price already in effect', function () {
        $current = Price::query()->where('amount_cents', 150)->sole();
        $future = Price::create(['price_type_id' => $current->price_type_id, 'amount_cents' => 200, 'valid_from' => '2027-01-01']);

        expect($this->admin->can('update', $current))->toBeFalse()
            ->and($this->admin->can('delete', $current))->toBeFalse()
            ->and($this->admin->can('update', $future))->toBeTrue()
            ->and(User::factory()->withRoles(Role::Payroll)->create()->can('create', Price::class))->toBeFalse();
    });
});

it('edits a slot cutoff', function () {
    $this->actingAs($this->admin);

    Livewire::test(ManageMealSlots::class)
        ->callTableAction('edit', lunch(), ['cutoff_days_before' => 1, 'cutoff_time' => '13:30'])
        ->assertHasNoTableActionErrors();

    expect(lunch()->cutoff_days_before)->toBe(1)->and(substr(lunch()->cutoff_time, 0, 5))->toBe('13:30');
});

it('creates a closed day for one slot', function () {
    $this->actingAs($this->admin);

    Livewire::test(ManageClosedDays::class)
        ->callAction('create', ['date' => '2026-12-24', 'meal_slot_id' => dinner()->id, 'note' => 'Štedrý večer'])
        ->assertHasNoActionErrors();

    expect(ClosedDay::sole())->meal_slot_id->toBe(dinner()->id)->note->toBe('Štedrý večer');
});

it('saves ordering settings', function () {
    $this->actingAs($this->admin);

    Livewire::test(OrderingSettings::class)
        ->set('horizon', 21)->set('maxExtra', 2)->call('save')->assertHasNoErrors();
    expect(app(Settings::class)->orderingHorizonDays())->toBe(21);

    Livewire::test(OrderingSettings::class)->set('maxExtra', 99)->call('save')->assertHasErrors('maxExtra');
});

describe('staff users', function () {
    it('creates a staff user with roles and a hashed password', function () {
        $this->actingAs($this->admin);

        Livewire::test(ManageUsers::class)
            ->callAction('create', ['name' => 'Kuchárka', 'email' => 'Kuch@Firma.sk', 'roles' => ['kitchen'], 'is_active' => true, 'password' => 'dostatocne-dlhe'])
            ->assertHasNoActionErrors();

        $u = User::query()->where('email', 'kuch@firma.sk')->sole();
        expect($u->hasRole(Role::Kitchen))->toBeTrue()
            ->and(password_verify('dostatocne-dlhe', $u->password))->toBeTrue();
    });

    it('keeps the password when left empty and forbids removing own admin role', function () {
        $this->actingAs($this->admin);
        $hash = $this->admin->password;

        Livewire::test(ManageUsers::class)
            ->callTableAction('edit', $this->admin, ['roles' => ['kitchen'], 'password' => ''])
            ->assertHasTableActionErrors(['roles']);

        Livewire::test(ManageUsers::class)
            ->callTableAction('edit', $this->admin, ['name' => 'Roman', 'password' => ''])
            ->assertHasNoTableActionErrors();
        expect($this->admin->fresh()->password)->toBe($hash);
    });

    it('lets an admin disable a lost 2FA', function () {
        $this->actingAs($this->admin);
        $u = User::factory()->kitchen()->create();
        $u->forceFill(['app_authentication_secret' => 'SECRET', 'app_authentication_recovery_codes' => ['a']])->save();

        Livewire::test(ManageUsers::class)->callTableAction('disable2fa', $u);
        expect($u->fresh()->app_authentication_secret)->toBeNull();
    });
});
