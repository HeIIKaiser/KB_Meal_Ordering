<?php

use App\Domain\Ordering\Actor;
use App\Domain\Ordering\OrderService;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\PriceType;
use Illuminate\Support\Facades\DB;

/*
 * Fires several real processes at the same time, each placing an order for the
 * same employee/slot/day. Needs MySQL/MariaDB (SQLite has no row locks) and pcntl:
 *
 *   DB_CONNECTION=mysql DB_DATABASE=jedalen_test ... ./vendor/bin/pest tests/Concurrency
 */
it('serialises simultaneous orders of one employee (row locking)', function () {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Needs MySQL/MariaDB and the pcntl extension.');
    }

    travelToLocal('2026-11-10 07:00:00');
    $items = menuItems('2026-11-10', lunch(), 2);
    $employee = employee();

    $dir = sys_get_temp_dir().'/jedalen-race-'.uniqid();
    mkdir($dir);
    $workers = 6;

    DB::disconnect();
    $pids = [];
    for ($i = 0; $i < $workers; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            // child
            DB::reconnect();
            while (! file_exists("$dir/go")) {
                usleep(200);
            }
            try {
                app(OrderService::class)->place($employee, $items[$i % 2], Actor::employee($employee), acceptExtra: true);
                $result = 'ok';
            } catch (Throwable $e) {
                $result = get_class($e).': '.$e->getMessage();
            }
            file_put_contents("$dir/$i", $result);
            posix_kill(getmypid(), SIGKILL); // skip PHPUnit shutdown handlers in the child
        }
        $pids[] = $pid;
    }

    touch("$dir/go");
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
    DB::reconnect();

    $results = collect(range(0, $workers - 1))->map(fn ($i) => file_get_contents("$dir/$i"));
    array_map('unlink', glob("$dir/*"));
    rmdir($dir);

    $active = Order::query()->where('employee_id', $employee->id)->where('status', OrderStatus::Active->value)->get();

    expect($results->filter(fn ($r) => $r === 'ok'))->toHaveCount(2)
        ->and($active)->toHaveCount(2)
        ->and($active->where('price_type_id', PriceType::byCode('donated')->id))->toHaveCount(1)
        ->and($active->where('price_type_id', PriceType::byCode('extra')->id))->toHaveCount(1)
        ->and($results->reject(fn ($r) => $r === 'ok')->every(fn ($r) => str_contains($r, 'maximálny počet')))->toBeTrue();
});
