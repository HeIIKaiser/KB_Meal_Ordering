<?php

namespace App\Support\Spreadsheet;

use DateTimeInterface;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Reads the first sheet of an XLSX or a CSV file into associative rows keyed by
 * normalised header names (lower-case, no diacritics, spaces → "_"), so
 * "Osobné číslo" and "osobne_cislo" are the same column.
 *
 * CSV: delimiter (; , or tab) and encoding (UTF-8 or Windows-1250, as saved
 * by Slovak Excel) are detected. Streaming reader — fine for shared hosting.
 */
class SheetReader
{
    public const MAX_ROWS = 5000;

    /**
     * @return list<array{line: int, values: array<string, string|DateTimeInterface|null>}>
     */
    public function read(string $path, ?string $originalName = null): array
    {
        $ext = strtolower(pathinfo($originalName ?? $path, PATHINFO_EXTENSION));

        return match ($ext) {
            'xlsx' => $this->readXlsx($path),
            'csv', 'txt' => $this->readCsv($path),
            default => throw new RuntimeException('Nepodporovaný typ súboru. Použite XLSX alebo CSV.'),
        };
    }

    public static function normalizeHeader(string $header): string
    {
        $h = trim(preg_replace('/^\xEF\xBB\xBF/', '', $header));
        $h = Str::ascii(mb_strtolower($h));
        $h = preg_replace('/[^a-z0-9]+/', '_', $h);

        return trim($h, '_');
    }

    private function readXlsx(string $path): array
    {
        $reader = new XlsxReader;
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                return $this->collect($sheet->getRowIterator(), fn ($row) => $row->toArray());
            }
        } finally {
            $reader->close();
        }

        return [];
    }

    private function readCsv(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Súbor sa nedá prečítať.');
        }

        $options = new CsvOptions;
        $firstLine = strtok($raw, "\n") ?: '';
        $options->FIELD_DELIMITER = collect([';', ',', "\t"])->sortByDesc(fn ($d) => substr_count($firstLine, $d))->first();
        $options->ENCODING = mb_check_encoding($raw, 'UTF-8') ? 'UTF-8' : 'CP1250'; // converted with iconv

        $reader = new CsvReader($options);
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                return $this->collect($sheet->getRowIterator(), fn ($row) => $row->toArray());
            }
        } finally {
            $reader->close();
        }

        return [];
    }

    private function collect(iterable $rows, callable $toArray): array
    {
        $headers = null;
        $result = [];
        $line = 0;

        foreach ($rows as $row) {
            $line++;
            $cells = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $toArray($row));

            if ($headers === null) {
                if (collect($cells)->filter(fn ($v) => $v !== null && $v !== '')->isEmpty()) {
                    continue; // leading empty rows
                }
                $headers = array_map(fn ($h) => self::normalizeHeader((string) $h), $cells);

                continue;
            }

            if (collect($cells)->filter(fn ($v) => $v !== null && $v !== '')->isEmpty()) {
                continue;
            }

            $values = [];
            foreach ($headers as $i => $h) {
                if ($h !== '') {
                    $v = $cells[$i] ?? null;
                    $values[$h] = $v instanceof DateTimeInterface ? $v : ($v === null ? null : (is_float($v) && floor($v) == $v ? (string) (int) $v : (string) $v));
                }
            }
            $result[] = ['line' => $line, 'values' => $values];

            if (count($result) > self::MAX_ROWS) {
                throw new RuntimeException('Súbor má príliš veľa riadkov (max. '.self::MAX_ROWS.').');
            }
        }

        if ($headers === null) {
            throw new RuntimeException('Súbor je prázdny.');
        }

        return $result;
    }
}
