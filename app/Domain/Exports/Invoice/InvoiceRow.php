<?php

namespace App\Domain\Exports\Invoice;

use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** One invoiced person's month: counts per meal, unit prices, total, and the order lines for the detail sheet. */
final readonly class InvoiceRow
{
    /**
     * @param  list<int>  $unitPrices  distinct unit prices (cents)
     * @param  Collection<int, array{date: CarbonImmutable, meal: string, menu: string, cents: int}>  $lines
     */
    public function __construct(
        public Employee $employee,
        public int $lunches,
        public int $dinners,
        public int $other,
        public array $unitPrices,
        public int $totalCents,
        public Collection $lines,
    ) {}
}
