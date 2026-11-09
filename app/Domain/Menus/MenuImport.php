<?php

namespace App\Domain\Menus;

use App\Models\MealSlot;
use App\Support\Allergens;
use App\Support\LocalTime;
use App\Support\Spreadsheet\SheetReader;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Generic menu import (template columns: datum, jedlo, cislo_menu, nazov, alergeny).
 *
 * OPEN (brief §13.5): the kitchen's real weekly file format — add a separate
 * adapter that produces the same rows once Roman sends a sample.
 */
class MenuImport
{
    public const COLUMNS = ['datum', 'jedlo', 'cislo_menu', 'nazov', 'alergeny'];

    public function __construct(private SheetReader $reader, private MenuWriter $writer) {}

    /**
     * Parse + validate + plan. Rows with errors are reported and skipped on apply.
     *
     * @return array{rows: list<array<string, mixed>>, errors: list<array{line: int, messages: list<string>}>, plan: Collection}
     */
    public function preview(string $path, ?string $originalName = null): array
    {
        $slots = MealSlot::query()->get();
        $valid = [];
        $errors = [];
        $seen = [];

        foreach ($this->reader->read($path, $originalName) as ['line' => $line, 'values' => $v]) {
            $messages = [];

            $date = $this->parseDate($v['datum'] ?? null);
            if (! $date) {
                $messages[] = 'Neplatný dátum „'.$this->show($v['datum'] ?? '').'“ (použite napr. 16.11.2026).';
            }

            $slotKey = Str::ascii(mb_strtolower(trim((string) ($v['jedlo'] ?? ''))));
            $slot = $slots->first(fn (MealSlot $s) => $s->code === $slotKey || Str::ascii(mb_strtolower($s->name)) === $slotKey);
            if (! $slot) {
                $messages[] = 'Neznáme jedlo „'.$this->show($v['jedlo'] ?? '').'“ (povolené: '.$slots->pluck('code')->join(', ').').';
            }

            $number = trim((string) ($v['cislo_menu'] ?? ''));
            if (! ctype_digit($number) || (int) $number < 1 || (int) $number > 20) {
                $messages[] = 'Číslo menu musí byť celé číslo 1 – 20.';
            }

            $name = trim((string) ($v['nazov'] ?? ''));
            if ($name === '') {
                $messages[] = 'Chýba názov jedla.';
            } elseif (mb_strlen($name) > 255) {
                $messages[] = 'Názov je dlhší ako 255 znakov.';
            }

            $allergens = (string) ($v['alergeny'] ?? '');
            if (! Allergens::isValid($allergens)) {
                $messages[] = 'Alergény musia byť čísla 1 – 14 oddelené čiarkou.';
            }

            if ($messages === [] && $date && $slot) {
                $key = $date->toDateString().'|'.$slot->id.'|'.(int) $number;
                if (isset($seen[$key])) {
                    $messages[] = 'Duplicitný riadok (rovnaký dátum, jedlo a číslo menu ako riadok '.$seen[$key].').';
                }
                $seen[$key] = $line;
            }

            if ($messages !== []) {
                $errors[] = ['line' => $line, 'messages' => $messages];

                continue;
            }

            $valid[] = [
                'line' => $line,
                'date' => $date->toDateString(),
                'meal_slot_id' => $slot->id,
                'number' => (int) $number,
                'name' => $name,
                'allergens' => $allergens,
            ];
        }

        return ['rows' => $valid, 'errors' => $errors, 'plan' => $this->writer->plan($valid)];
    }

    /** Template file content (CSV, UTF-8 with BOM so Excel shows diacritics). */
    public static function templateCsv(): string
    {
        $monday = LocalTime::today()->startOfWeek()->addWeek();

        $lines = [implode(';', self::COLUMNS)];
        foreach ([
            [0, 'obed', 1, 'Hovädzí guláš, knedľa', '1,3,7'],
            [0, 'obed', 2, 'Zeleninové rizoto', '7,9'],
            [0, 'vecera', 1, 'Palacinky s džemom', '1,3,7'],
        ] as [$d, $slot, $n, $name, $al]) {
            $lines[] = implode(';', [$monday->addDays($d)->format('j.n.Y'), $slot, $n, $name, '"'.$al.'"']);
        }

        return "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n";
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return LocalTime::date($value->format('Y-m-d'));
        }

        $value = trim((string) $value);

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m)
            || preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})$/', $value, $m)) {
            [$y, $mo, $d] = strlen($m[1]) === 4 ? [$m[1], $m[2], $m[3]] : [$m[3], $m[2], $m[1]];

            return checkdate((int) $mo, (int) $d, (int) $y) ? LocalTime::date(sprintf('%04d-%02d-%02d', $y, $mo, $d)) : null;
        }

        // Excel serial date stored as a number in CSV
        if (ctype_digit($value) && (int) $value > 40000 && (int) $value < 80000) {
            return LocalTime::date(CarbonImmutable::create(1899, 12, 30)->addDays((int) $value)->format('Y-m-d'));
        }

        return null;
    }

    private function show(mixed $v): string
    {
        return $v instanceof DateTimeInterface ? $v->format('Y-m-d') : mb_substr((string) $v, 0, 40);
    }
}
