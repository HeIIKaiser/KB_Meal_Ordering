<?php

namespace App\Domain\Exports\Invoice;

/** An invoicing export format for accounting. Register new ones in InvoiceFormats::CLASSES. */
interface InvoiceFormat
{
    public function key(): string;

    public function label(): string;

    public function isProvisional(): bool;

    public function fileName(string $month): string;

    public function contentType(): string;

    /** Writes the file and returns its path (deleted after download). */
    public function write(InvoiceData $data): string;
}
