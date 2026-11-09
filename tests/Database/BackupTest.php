<?php

use App\Domain\Backup\DatabaseBackup;
use App\Domain\Ordering\Actor;
use App\Domain\Ordering\OrderService;
use App\Enums\Role;
use App\Filament\Pages\Backups;
use App\Models\Employee;
use App\Models\Order;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Runs without a wrapping transaction (restore drops/creates tables), on SQLite and MySQL/MariaDB.
 */
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/jedalen-backups-'.uniqid();
    config(['jedalen.backup_path' => $this->dir]);
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*') ?: []);
    @rmdir($this->dir);
});

it('creates a complete gzipped dump and restores it exactly', function () {
    travelToLocal('2026-11-10 07:00:00');
    $items = menuItems('2026-11-10', lunch(), 2);
    $e = employee(['last_name' => "Ďurčová-O'Neil", 'first_name' => 'Žofia', 'department' => null]);
    app(OrderService::class)->place($e, $items[0], Actor::employee($e), note: "dva riadky\nčo; DROP TABLE x; --");
    DB::table('sessions')->insert(['id' => 'abc', 'payload' => 'x', 'last_activity' => 1]);

    $path = app(DatabaseBackup::class)->create();

    expect(basename($path))->toBe('jedalen_2026-11-10_070000.sql.gz')
        ->and(glob($this->dir.'/*.part'))->toBe([]);

    $sql = gzdecode(file_get_contents($path));
    expect($sql)->toContain(DatabaseBackup::END_MARKER)
        ->and($sql)->not->toContain("'abc'"); // session rows are not backed up

    // every statement is on one line (restorable with any tool)
    foreach (explode("\n", $sql) as $line) {
        expect($line === '' || str_starts_with($line, '--') || str_ends_with($line, ';'))->toBeTrue($line);
    }

    // damage the data, then restore
    Order::query()->delete();
    Employee::query()->update(['first_name' => 'X']);

    app(DatabaseBackup::class)->restore($path);

    $order = Order::sole();
    expect($order->note)->toBe("dva riadky\nčo; DROP TABLE x; --")
        ->and($order->unit_price_cents)->toBe(150)
        ->and(Employee::query()->where('last_name', "Ďurčová-O'Neil")->sole()->first_name)->toBe('Žofia')
        ->and(Employee::query()->where('last_name', "Ďurčová-O'Neil")->sole()->department)->toBeNull()
        ->and(DB::table('sessions')->count())->toBe(0);

    // the app keeps working on the restored schema (unique guard still in place)
    expect(fn () => Order::create([...$order->only(['employee_id', 'meal_slot_id', 'price_type_id', 'unit_price_cents', 'menu_item_id']), 'date' => '2026-11-10', 'status' => 'active']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('refuses to restore an incomplete backup', function () {
    $path = app(DatabaseBackup::class)->create();
    $sql = gzdecode(file_get_contents($path));
    file_put_contents($path, gzencode(str_replace(DatabaseBackup::END_MARKER, '', $sql)));

    app(DatabaseBackup::class)->restore($path);
})->throws(RuntimeException::class, 'neúplná');

it('rotates old backups but always keeps the 7 newest', function () {
    $b = app(DatabaseBackup::class);
    mkdir($this->dir, 0750, true);
    foreach (range(1, 12) as $i) {
        $p = sprintf('%s/jedalen_2026-09-%02d_023000.sql.gz', $this->dir, $i);
        file_put_contents($p, 'x');
        touch($p, now()->subDays(60 - $i)->getTimestamp());
    }
    touch($this->dir.'/jedalen_2026-11-01_023000.sql.gz.part', now()->subDay()->getTimestamp());

    expect($b->prune(30))->toBe(5)
        ->and($b->list()->pluck('name')->first())->toBe('jedalen_2026-09-12_023000.sql.gz')
        ->and($b->list())->toHaveCount(7)
        ->and(glob($this->dir.'/*.part'))->toBe([]);
});

it('rejects path traversal when downloading', function () {
    $b = app(DatabaseBackup::class);
    expect($b->find('../../.env'))->toBeNull()
        ->and($b->find('jedalen_2026-11-10_023000.sql.gz'))->toBeNull(); // valid name, no file
});

it('lets admins list, create and download backups (audited) and nobody else', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs(User::factory()->withRoles(Role::Payroll)->create())->get('/admin/zalohy')->assertForbidden();
    $this->actingAs($admin)->get('/admin/zalohy')->assertOk()->assertSee('Zatiaľ žiadne zálohy')->assertSee('Pozor');

    $this->actingAs($admin);
    Livewire::test(Backups::class)->call('backupNow')->assertNotified('Záloha vytvorená');
    $name = app(DatabaseBackup::class)->latest()['name'];

    $this->get('/admin/zalohy')->assertSee($name)->assertDontSee('Pozor:');
    $this->get('/admin/zalohy/stiahnut/'.$name)->assertOk()->assertDownload($name);
    $this->get('/admin/zalohy/stiahnut/..%2F..%2F.env')->assertNotFound();
    $this->actingAs(User::factory()->kitchen()->create())->get('/admin/zalohy/stiahnut/'.$name)->assertForbidden();

    expect(DB::table('activity_log')->where('log_name', 'backups')->pluck('event')->all())->toBe(['created', 'downloaded']);
});

it('runs from the command line and the scheduler', function () {
    $this->artisan('app:backup')->assertSuccessful();
    expect(app(DatabaseBackup::class)->list())->toHaveCount(1);

    $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'database-backup');
    expect($event)->not->toBeNull()->and($event->expression)->toBe('30 2 * * *')->and($event->timezone)->toBe('Europe/Bratislava');
});
