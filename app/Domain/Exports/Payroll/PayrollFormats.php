<?php

namespace App\Domain\Exports\Payroll;

use InvalidArgumentException;

/** Registry of payroll export formats. */
final class PayrollFormats
{
    /** @var list<class-string<PayrollFormat>> */
    public const CLASSES = [
        VemaFormat::class,
    ];

    /** @return array<string, PayrollFormat> key → format */
    public static function all(): array
    {
        $out = [];
        foreach (self::CLASSES as $class) {
            $format = app($class);
            $out[$format->key()] = $format;
        }

        return $out;
    }

    public static function get(string $key): PayrollFormat
    {
        return self::all()[$key] ?? throw new InvalidArgumentException("Unknown payroll format $key");
    }
}
