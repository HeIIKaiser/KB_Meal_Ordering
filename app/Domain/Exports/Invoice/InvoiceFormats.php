<?php

namespace App\Domain\Exports\Invoice;

use InvalidArgumentException;

/** Registry of invoicing export formats. */
final class InvoiceFormats
{
    /** @var list<class-string<InvoiceFormat>> */
    public const CLASSES = [
        BillingXlsxFormat::class,
    ];

    /** @return array<string, InvoiceFormat> */
    public static function all(): array
    {
        $out = [];
        foreach (self::CLASSES as $class) {
            $format = app($class);
            $out[$format->key()] = $format;
        }

        return $out;
    }

    public static function get(string $key): InvoiceFormat
    {
        return self::all()[$key] ?? throw new InvalidArgumentException("Unknown invoice format $key");
    }
}
