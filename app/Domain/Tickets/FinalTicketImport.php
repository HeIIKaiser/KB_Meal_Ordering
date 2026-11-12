<?php

namespace App\Domain\Tickets;

use App\Enums\OrderStatus;
use App\Models\Employee;
use App\Models\Order;
use App\Models\TicketOverride;
use App\Models\User;
use App\Support\Spreadsheet\SheetReader;

/**
 * Month-end import of the final ticket counts (from attendance, outside the app).
 *
 * File (XLSX/CSV, same tolerance as the other imports): columns
 *   osobne_cislo ; listky        (priezvisko, meno … are ignored)
 * The counts become that month's individual overrides; the month is repriced.
 */
class FinalTicketImport
{
    private const NUMBER = ['osobne_cislo', 'os_cislo', 'osobne_c', 'cislo', 'osc'];

    private const TICKETS = ['listky', 'pocet_listkov', 'listky_spolu', 'stravne_listky', 'tickets'];

    public function __construct(
        private SheetReader $reader,
        private TicketRoster $roster,
        private TicketAllocation $allocation,
        private TicketAdmin $admin,
    ) {}

    /**
     * @return array{month: string, rows: list<array>, errors: list<array{line: int, messages: list<string>}>, missing: list<array{name: string, osobne_cislo: string, allocated: int}>, totals: array{orders: int, before: int, after: int}}
     */
    public function preview(string $path, ?string $originalName, string $month, User $by): array
    {
        $roster = $this->roster->employees($month)->keyBy(fn ($r) => (string) $r['employee']->osobne_cislo);
        $all = Employee::query()->pluck('id', 'osobne_cislo');
        $rows = [];
        $errors = [];
        $seen = [];
        $counts = [];

        foreach ($this->reader->read($path, $originalName) as ['line' => $line, 'values' => $values]) {
            $number = trim((string) $this->pick($values, self::NUMBER));
            $raw = trim((string) $this->pick($values, self::TICKETS));
            $messages = [];

            if ($number === '' && $raw === '') {
                continue; // empty line
            }
            if ($number === '') {
                $messages[] = 'Chýba osobné číslo.';
            } elseif (isset($seen[$number])) {
                $messages[] = "Osobné číslo $number je v súbore viackrát (aj riadok {$seen[$number]}).";
            } elseif (! isset($all[$number])) {
                $messages[] = "Neznáme osobné číslo $number.";
            } elseif (! isset($roster[$number])) {
                $messages[] = "Zamestnanec $number nie je v ".Month::label($month).' v režime lístkov.';
            }
            if (! preg_match('/^\d{1,3}([.,]0+)?$/', $raw)) {
                $messages[] = 'Počet lístkov musí byť celé nezáporné číslo (je „'.$raw.'“).';
            }
            $seen[$number] = $line;

            if ($messages) {
                $errors[] = ['line' => $line, 'messages' => $messages];

                continue;
            }

            $entry = $roster[$number];
            $tickets = (int) $raw;
            $counts[$entry['employee']->id] = $tickets;
            $rows[$entry['employee']->id] = [
                'line' => $line,
                'employee_id' => $entry['employee']->id,
                'osobne_cislo' => $number,
                'name' => $entry['employee']->fullNameReversed(),
                'old' => $this->allocation->allocation($entry['employee'], $month, $entry['calendar']),
                'new' => $tickets,
                'used' => $this->allocation->used($entry['employee'], $month),
                'before' => $this->monthCents($entry['employee']->id, $month),
            ];
        }

        // Simulate the save → exact per-employee effect.
        $result = $counts ? $this->admin->preview($month, [], $counts, $by, TicketOverride::SOURCE_IMPORT, 'month_import') : new RepriceResult;
        $byEmployee = collect($result->changes)->groupBy('employee_id');
        foreach ($rows as $id => &$row) {
            $changes = $byEmployee[$id] ?? collect();
            $row['changed'] = $changes->count();
            $row['after'] = $row['before'] + $changes->sum(fn ($c) => $c['new_cents'] - $c['old_cents']);
        }
        unset($row);

        $missing = $roster->reject(fn ($r, $number) => isset($seen[$number]))
            ->map(fn ($r) => [
                'name' => $r['employee']->fullNameReversed(),
                'osobne_cislo' => (string) $r['employee']->osobne_cislo,
                'allocated' => $this->allocation->allocation($r['employee'], $month, $r['calendar']),
            ])->values()->all();

        return [
            'month' => $month,
            'rows' => array_values($rows),
            'errors' => $errors,
            'missing' => $missing,
            'totals' => ['orders' => $result->orders(), 'before' => array_sum(array_column($rows, 'before')), 'after' => array_sum(array_column($rows, 'after'))],
        ];
    }

    public function apply(array $preview, User $by): RepriceResult
    {
        $counts = collect($preview['rows'])->mapWithKeys(fn ($r) => [$r['employee_id'] => $r['new']])->all();
        $result = $this->admin->save($preview['month'], [], $counts, $by, TicketOverride::SOURCE_IMPORT, 'month_import');

        activity('tickets')->causedBy($by)->event('month_import')
            ->withProperties(['month' => $preview['month'], 'employees' => count($counts), 'repriced_orders' => $result->orders()])
            ->log('Konečné lístky '.Month::label($preview['month']).': '.count($counts).' zamestnancov, prepočítaných objednávok '.$result->orders());

        return $result;
    }

    public static function templateCsv(): string
    {
        return "\xEF\xBB\xBF"."osobne_cislo;listky;priezvisko;meno\r\n1001;19;Nováková;Mária\r\n1002;22;Kováč;Peter\r\n";
    }

    private function monthCents(int $employeeId, string $month): int
    {
        return (int) Order::query()->where('employee_id', $employeeId)->where('status', OrderStatus::Active->value)
            ->whereBetween('date', [Month::first($month)->toDateString(), Month::last($month)->toDateString()])
            ->sum('unit_price_cents');
    }

    private function pick(array $values, array $aliases): mixed
    {
        foreach ($aliases as $alias) {
            if (array_key_exists($alias, $values)) {
                return $values[$alias];
            }
        }

        return null;
    }
}
