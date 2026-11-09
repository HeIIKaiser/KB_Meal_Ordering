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
 * Menu import from XLSX/CSV. Two layouts, detected by the header row:
 *
 * 1. Weekly (kitchen) — ONE ROW PER DAY (Roman, 2026-11-09):
 *      datum | polievka | obed_1 | obed_2 | vecera   (+ optional "<column>_alergeny" columns)
 *    Column names: "polievka" = soup of the slot with has_soup; "<slot code>_<n>" = option n;
 *    "<slot code>" alone = option 1. Allergens may also be written at the end of a dish,
 *    e.g. "Hovädzí guláš, knedľa (1,3,7)", "… A: 1,3,7" or "… 1,3,7". Empty cells change nothing.
 *
 * 2. Long — one row per option: datum | jedlo | cislo_menu | nazov | alergeny.
 *
 * Both produce the same rows for MenuWriter (plan → preview → apply).
 */
class MenuImport
{
    public const COLUMNS = ['datum', 'jedlo', 'cislo_menu', 'nazov', 'alergeny'];

    public function __construct(private SheetReader $reader, private MenuWriter $writer) {}

    /**
     * @return array{rows: list<array<string, mixed>>, errors: list<array{line: int, messages: list<string>}>, plan: Collection, format: string}
     */
    public function preview(string $path, ?string $originalName = null): array
    {
        $sheet = $this->reader->read($path, $originalName);
        $headers = collect($sheet)->flatMap(fn ($r) => array_keys($r['values']))->unique();
        $slots = MealSlot::query()->ordered()->get();

        $long = $headers->contains('jedlo') && $headers->contains('nazov');
        [$valid, $errors] = $long ? $this->parseLong($sheet, $slots) : $this->parseWeekly($sheet, $slots, $headers);

        return [
            'rows' => $valid,
            'errors' => $errors,
            'plan' => $this->writer->plan($valid),
            'format' => $long ? 'long' : 'weekly',
        ];
    }

    /** Template: the weekly layout, next Monday–Friday (CSV, UTF-8 with BOM for Excel). */
    public static function templateCsv(): string
    {
        $monday = LocalTime::today()->startOfWeek()->addWeek();
        $lines = ['datum;polievka;obed_1;obed_2;vecera'];
        $days = [
            ['Fazuľová polievka (1,9)', 'Hovädzí guláš, knedľa (1,3,7)', 'Zeleninové rizoto so syrom (7,9)', 'Palacinky s džemom (1,3,7)'],
            ['Paradajková polievka (1,9)', 'Kuracie prsia na prírodno, ryža (7)', 'Bryndzové halušky (1,3,7)', 'Zapekané cestoviny so šunkou (1,3,7)'],
            ['Hrachová polievka (1,9)', 'Segedínsky guláš, knedľa (1,3,7)', 'Rybie prsty, zemiaková kaša (1,3,4,7)', 'Ryžový nákyp s ovocím (3,7)'],
            ['Brokolicová polievka (1,7,9)', 'Plnená paprika, zemiaky (1,3,7)', 'Vyprážaný syr, hranolky (1,3,7)', 'Pizza toast so syrom (1,7)'],
            ['Kapustnica (1,9)', 'Pečené kuracie stehno, ryža (9)', 'Šošovicový prívarok, vajce (1,3)', 'Krupicová kaša s kakaom (1,7)'],
        ];
        foreach ($days as $i => $d) {
            $lines[] = $monday->addDays($i)->format('j.n.Y').';'.implode(';', array_map(fn ($c) => '"'.$c.'"', $d));
        }

        return "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n";
    }

    /**
     * Split "Hovädzí guláš, knedľa (1,3,7)" into name + allergens. Accepts "(1,3,7)", "[1,3,7]",
     * "/1,3,7/", "A: 1,3,7" or a bare trailing "1,3,7" (all numbers must be 1–14).
     *
     * @return array{0: string, 1: ?string}
     */
    public static function splitAllergens(string $text): array
    {
        $text = trim($text);

        if (preg_match('/^(.*?)[\s,;-]*(?:[\(\[\/]\s*(?:a(?:lerg[ée]ny)?\s*[:.]?\s*)?([\d\s,.;]+?)\s*[\)\]\/]|(?:a(?:lerg[ée]ny)?\s*[:.]\s*|\s)(\d{1,2}(?:\s*[,.;]\s*\d{1,2})*))\s*$/iu', $text, $m)) {
            $list = $m[2] !== '' ? $m[2] : ($m[3] ?? '');
            $name = trim($m[1], " \t,;-");
            if ($name !== '' && Allergens::isValid($list) && Allergens::parse($list) !== []) {
                return [$name, Allergens::normalize($list)];
            }
        }

        return [$text, null];
    }

    /** @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} */
    private function parseWeekly(array $sheet, Collection $slots, Collection $headers): array
    {
        $soupSlot = $slots->first(fn (MealSlot $s) => $s->has_soup);

        // header → [slot, number|'soup']
        $columns = [];
        foreach ($headers as $h) {
            if ($h === 'polievka' && $soupSlot) {
                $columns[$h] = [$soupSlot, 'soup'];

                continue;
            }
            foreach ($slots as $slot) {
                $code = Str::ascii(mb_strtolower($slot->code));
                if ($h === $code) {
                    $columns[$h] = [$slot, 1];
                } elseif (preg_match('/^'.preg_quote($code, '/').'_(\d{1,2})$/', $h, $m) && (int) $m[1] >= 1) {
                    $columns[$h] = [$slot, (int) $m[1]];
                }
            }
        }

        if (! $headers->contains('datum') || $columns === []) {
            throw new \RuntimeException('Nerozpoznané stĺpce. Prvý riadok musí obsahovať napr. datum, polievka, obed_1, obed_2, vecera (alebo datum, jedlo, cislo_menu, nazov, alergeny). Stiahnite si šablónu.');
        }

        $valid = [];
        $errors = [];
        $seenDates = [];

        foreach ($sheet as ['line' => $line, 'values' => $v]) {
            $messages = [];
            $date = $this->parseDate($v['datum'] ?? null);

            if (! $date) {
                $errors[] = ['line' => $line, 'messages' => ['Neplatný dátum „'.$this->show($v['datum'] ?? '').'“ (použite napr. 16.11.2026).']];

                continue;
            }
            if (isset($seenDates[$date->toDateString()])) {
                $errors[] = ['line' => $line, 'messages' => ['Dátum '.$date->format('j.n.Y').' je v súbore dvakrát (aj riadok '.$seenDates[$date->toDateString()].').']];

                continue;
            }
            $seenDates[$date->toDateString()] = $line;

            $rows = [];
            foreach ($columns as $col => [$slot, $number]) {
                $cell = trim((string) ($v[$col] ?? ''));
                if ($cell === '') {
                    continue; // empty cell = no change
                }

                $explicit = trim((string) ($v[$col.'_alergeny'] ?? ''));
                [$name, $allergens] = $explicit !== '' ? [$cell, $explicit] : self::splitAllergens($cell);

                if (! Allergens::isValid($allergens)) {
                    $messages[] = "Stĺpec $col: alergény musia byť čísla 1 – 14.";

                    continue;
                }
                if (mb_strlen($name) > 255) {
                    $messages[] = "Stĺpec $col: názov je dlhší ako 255 znakov.";

                    continue;
                }

                $rows[] = [
                    'line' => $line,
                    'kind' => $number === 'soup' ? 'soup' : 'item',
                    'date' => $date->toDateString(),
                    'meal_slot_id' => $slot->id,
                    'number' => $number === 'soup' ? 0 : $number,
                    'name' => $name,
                    'allergens' => $allergens,
                ];
            }

            if ($messages !== []) {
                $errors[] = ['line' => $line, 'messages' => $messages];
            }
            array_push($valid, ...$rows);
        }

        return [$valid, $errors];
    }

    /** @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} */
    private function parseLong(array $sheet, Collection $slots): array
    {
        $valid = [];
        $errors = [];
        $seen = [];

        foreach ($sheet as ['line' => $line, 'values' => $v]) {
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

        return [$valid, $errors];
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
