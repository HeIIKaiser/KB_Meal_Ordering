<?php

namespace App\Domain\Exports\Payroll;

use App\Domain\Exports\MonthlyData;
use App\Models\PriceType;
use Illuminate\Support\Collection;

/**
 * Payroll numbers for a month, built from MonthlyData so they always equal the
 * monthly matrix (same counts, same € incl. mandatory charges).
 */
final class PayrollData
{
    /** @param Collection<int, PayrollRow> $rows */
    private function __construct(
        public readonly string $month,
        public readonly bool $closed,
        public readonly Collection $rows,
    ) {}

    public static function for(string $month): self
    {
        $data = MonthlyData::for($month);
        $donated = PriceType::byCode(PriceType::DONATED)->id;
        $extra = PriceType::byCode(PriceType::EXTRA)->id;

        $rows = $data->employees
            ->map(fn (array $e) => new PayrollRow(
                $e['employee'],
                MonthlyData::count($e, $donated),
                MonthlyData::count($e, $extra),
                MonthlyData::mandatoryCount($e),
                MonthlyData::totalCents($e),
            ))
            ->filter(fn (PayrollRow $r) => $r->totalCents > 0)
            ->values();

        return new self($month, $data->closed, $rows);
    }

    public function totalCents(): int
    {
        return $this->rows->sum(fn (PayrollRow $r) => $r->totalCents);
    }
}
