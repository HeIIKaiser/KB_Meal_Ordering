<?php

use App\Domain\Ordering\Actor;
use App\Domain\Ordering\OrderService;
use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/*
 * Ticket mode under load: parallel orders of one employee must never use more tickets
 * than allocated (row lock on the employee + repricing inside the same transaction).
 * Needs MySQL/MariaDB and pcntl, like SimultaneousOrdersTest.
 */
it('never prices more meals at 1,50 € than the employee has tickets', function () {
    if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Needs MySQL/MariaDB and the pcntl extension.');
    }

    travelToLocal('2026-11-10 07:00:00');
    $employee = joinGroup(employee(), ticketGroup(3));
    $items = collect(['2026-11-10', '2026-11-11', '2026-11-12'])
        ->flatMap(fn ($d) => menuItems($d, lunch(), 2))->values();

    $dir = sys_get_temp_dir().'/jedalen-tickets-'.uniqid();
    mkdir($dir);
    $workers = 6;

    DB::disconnect();
    $pids = [];
    for ($i = 0; $i < $workers; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::reconnect();
            while (! file_exists("$dir/go")) {
                usleep(200);
            }
            try {
                app(OrderService::class)->place($employee, $items[$i], Actor::employee($employee), acceptExtra: true);
                $result = 'ok';
            } catch (Throwable $e) {
                $result = get_class($e).': '.$e->getMessage();
            }
            file_put_contents("$dir/$i", $result);
            posix_kill(getmypid(), SIGKILL);
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

    expect($results->every(fn ($r) => $r === 'ok'))->toBeTrue()
        ->and($active)->toHaveCount(6)
        ->and($active->where('unit_price_cents', 150))->toHaveCount(3)
        ->and($active->where('unit_price_cents', 600))->toHaveCount(3)
        ->and(prices($employee))->toBe([150, 150, 150, 600, 600, 600]); // earliest meals hold the tickets
});
