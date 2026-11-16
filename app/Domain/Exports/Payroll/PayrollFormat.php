<?php

namespace App\Domain\Exports\Payroll;

/**
 * A payroll export format ("Vema – zrážky zo mzdy", later others). Register new ones in
 * PayrollFormats::CLASSES.
 */
interface PayrollFormat
{
    /** Short key used in the URL, e.g. "vema". */
    public function key(): string;

    /** Label in the UI, e.g. „Formát Vema – predbežný“. */
    public function label(): string;

    /** True while the layout is not confirmed with a real sample of the target system. */
    public function isProvisional(): bool;

    /** @return list<string> problems that block the export (e.g. missing Vema numbers); empty = OK */
    public function validate(PayrollData $data): array;

    public function fileName(string $month): string;

    public function contentType(): string;

    /** Writes the file and returns its path (deleted after download). */
    public function write(PayrollData $data): string;
}
