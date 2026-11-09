<?php

namespace App\Domain\Employees;

use App\Models\Employee;
use App\Support\Spreadsheet\SheetReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Employee import from XLSX/CSV, upsert by osobné číslo. Never deletes;
 * optionally deactivates employees missing from the file.
 *
 * Recognised columns (header names, diacritics/case ignored):
 *   osobne_cislo (required), priezvisko, meno, stredisko | oddelenie, aktivny (áno/nie, 1/0)
 */
class EmployeeImport
{
    private const ALIASES = [
        'osobne_cislo' => ['osobne_cislo', 'os_cislo', 'osobne_c', 'cislo', 'osc'],
        'last_name' => ['priezvisko', 'last_name'],
        'first_name' => ['meno', 'first_name', 'krstne_meno'],
        'department' => ['stredisko', 'oddelenie', 'department', 'nazov_strediska'],
        'is_active' => ['aktivny', 'aktivna', 'active', 'is_active'],
    ];

    public function __construct(private SheetReader $reader) {}

    /**
     * @return array{rows: list<array<string, mixed>>, errors: list<array{line: int, messages: list<string>}>, missing: list<array{id: int, name: string, osobne_cislo: string}>}
     */
    public function preview(string $path, ?string $originalName = null): array
    {
        $existing = Employee::query()->get()->keyBy('osobne_cislo');
        $rows = [];
        $errors = [];
        $seen = [];

        foreach ($this->reader->read($path, $originalName) as ['line' => $line, 'values' => $values]) {
            $v = $this->map($values);
            $messages = [];

            $number = trim((string) $v['osobne_cislo']);
            if ($number === '' || mb_strlen($number) > 32) {
                $messages[] = 'Chýba alebo je neplatné osobné číslo.';
            } elseif (isset($seen[$number])) {
                $messages[] = "Osobné číslo $number je v súbore viackrát (aj riadok {$seen[$number]}).";
            }
            $seen[$number] = $line;

            $employee = $existing[$number] ?? null;
            $data = array_filter([
                'last_name' => $this->clean($v['last_name']),
                'first_name' => $this->clean($v['first_name']),
                'department' => $this->clean($v['department']),
            ], fn ($x) => $x !== null);

            if (! $employee && (! isset($data['last_name']) || ! isset($data['first_name']))) {
                $messages[] = 'Nový zamestnanec musí mať meno aj priezvisko.';
            }

            if ($v['is_active'] !== null && $v['is_active'] !== '') {
                $active = $this->bool($v['is_active']);
                if ($active === null) {
                    $messages[] = 'Stĺpec „aktívny“ musí byť áno/nie alebo 1/0.';
                } else {
                    $data['is_active'] = $active;
                }
            } elseif (! $employee) {
                $data['is_active'] = true;
            }

            if ($messages !== []) {
                $errors[] = ['line' => $line, 'messages' => $messages];

                continue;
            }

            $changes = [];
            if ($employee) {
                foreach ($data as $field => $value) {
                    if ($employee->{$field} !== $value) {
                        $changes[$field] = ['old' => $employee->{$field}, 'new' => $value];
                    }
                }
            }

            $rows[] = [
                'line' => $line,
                'osobne_cislo' => $number,
                'data' => $data,
                'action' => $employee ? ($changes ? 'update' : 'unchanged') : 'create',
                'changes' => $changes,
                'name' => trim(($data['last_name'] ?? $employee?->last_name).' '.($data['first_name'] ?? $employee?->first_name)),
            ];
        }

        $missing = $existing->filter(fn (Employee $e) => $e->is_active && ! isset($seen[$e->osobne_cislo]))
            ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->fullNameReversed(), 'osobne_cislo' => $e->osobne_cislo])
            ->sortBy('name')->values()->all();

        return ['rows' => $rows, 'errors' => $errors, 'missing' => $missing];
    }

    /** @return array{created: int, updated: int, deactivated: int, created_ids: list<int>} New employees get a PIN automatically. */
    public function apply(array $preview, bool $deactivateMissing = false): array
    {
        $result = ['created' => 0, 'updated' => 0, 'deactivated' => 0, 'created_ids' => []];

        DB::transaction(function () use ($preview, $deactivateMissing, &$result) {
            foreach ($preview['rows'] as $row) {
                if ($row['action'] === 'create') {
                    $result['created_ids'][] = Employee::query()->create(['osobne_cislo' => $row['osobne_cislo'], ...$row['data']])->id;
                    $result['created']++;
                } elseif ($row['action'] === 'update') {
                    Employee::query()->where('osobne_cislo', $row['osobne_cislo'])->firstOrFail()->update($row['data']);
                    $result['updated']++;
                }
            }

            if ($deactivateMissing) {
                foreach ($preview['missing'] as $m) {
                    Employee::query()->findOrFail($m['id'])->update(['is_active' => false]);
                    $result['deactivated']++;
                }
            }
        });

        return $result;
    }

    public static function templateCsv(): string
    {
        return "\xEF\xBB\xBF"."osobne_cislo;priezvisko;meno;stredisko;aktivny\r\n1001;Nováková;Mária;Recepcia;áno\r\n1002;Kováč;Peter;Kuchyňa;áno\r\n";
    }

    private function map(array $values): array
    {
        $out = [];
        foreach (self::ALIASES as $field => $aliases) {
            $out[$field] = null;
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $values)) {
                    $out[$field] = $values[$alias];
                    break;
                }
            }
        }

        return $out;
    }

    private function clean(mixed $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, 100);
    }

    private function bool(mixed $v): ?bool
    {
        $v = Str::ascii(mb_strtolower(trim((string) $v)));

        return match ($v) {
            '1', 'ano', 'a', 'yes', 'true', 'x' => true,
            '0', 'nie', 'n', 'no', 'false' => false,
            default => null,
        };
    }
}
