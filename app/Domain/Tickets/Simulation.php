<?php

namespace App\Domain\Tickets;

use Illuminate\Support\Facades\DB;

/**
 * Previews run the real change inside a transaction that is always rolled back, so
 * the preview numbers are exactly what saving would do.
 */
final class Simulation
{
    /**
     * @template T
     *
     * @param  callable(): T  $change
     * @return T
     */
    public static function run(callable $change): mixed
    {
        DB::beginTransaction();
        try {
            return $change();
        } finally {
            DB::rollBack();
        }
    }
}
