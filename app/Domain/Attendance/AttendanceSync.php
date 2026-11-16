<?php

namespace App\Domain\Attendance;

use App\Domain\Tickets\ClosedMonths;
use App\Domain\Tickets\Month;
use App\Domain\Tickets\Simulation;
use App\Models\AttendanceDay;
use App\Models\AttendanceResult;
use App\Models\AttendanceSync as SyncLog;
use App\Models\Employee;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Receives one month from the bridge (docs/ATTENDANCE.md §4.3):
 *  - replaces the mirror of that month (idempotent),
 *  - open month: recomputes the estimate of everyone affected and reprices,
 *  - closed month: changes nothing, records the differences as a warning,
 *  - lists personal numbers not found in the app (nothing is dropped silently).
 */
class AttendanceSync
{
    public function __construct(
        private Recalculator $recalculator,
        private ClosedMonths $closedMonths,
        private EmployeeMatcher $matcher,
        private AttendanceRoster $roster,
        private Settings $settings,
    ) {}

    /** @return array{month: string, employees: int, days: int, unmatched: list<string>, recomputed: int, closed: bool, late_changes: list<array>} */
    public function ingest(array $payload, ?string $ip = null): array
    {
        $month = $this->validate($payload);
        $now = now();
        $rows = [];
        foreach ($payload['employees'] as $emp) {
            $number = Employee::attendanceNumber((string) $emp['osobne_cislo']);
            foreach ($emp['days'] as $d) {
                $key = $number.'|'.$d['date'];
                if (isset($rows[$key])) { // same person twice (e.g. "029" and "29"): merge like two employments
                    $rows[$key]['planned_seconds'] += (int) ($d['planned_seconds'] ?? 0);
                    $rows[$key]['total_seconds'] += (int) ($d['total_seconds'] ?? 0);
                    $rows[$key]['absences'] = json_encode(array_values(array_unique([...json_decode($rows[$key]['absences'], true), ...array_map('intval', $d['absences'] ?? [])])));

                    continue;
                }
                $rows[$key] = [
                    'osobne_cislo' => $number,
                    'date' => $d['date'],
                    'planned_seconds' => (int) ($d['planned_seconds'] ?? 0),
                    'plan_name' => isset($d['plan_name']) ? mb_substr((string) $d['plan_name'], 0, 60) : null,
                    'total_seconds' => (int) ($d['total_seconds'] ?? 0),
                    'absences' => json_encode(array_values(array_map('intval', $d['absences'] ?? []))),
                    'status' => isset($d['status']) ? (int) $d['status'] : null,
                    'month_locked' => (bool) ($emp['month_locked'] ?? false),
                    'synced_at' => $now,
                ];
            }
        }

        $numbers = collect($payload['employees'])->map(fn ($e) => Employee::attendanceNumber((string) $e['osobne_cislo']))->unique()->values();
        $unmatched = $numbers->reject(fn ($n) => $this->matcher->find($n))->values()->all();
        $closed = $this->closedMonths->isClosed($month);

        // Closed month: compare what the new data would give with the stored final values first.
        $before = $closed ? $this->finalSnapshot($month) : null;

        DB::transaction(function () use ($month, $rows) {
            AttendanceDay::query()->whereBetween('date', [Month::first($month)->toDateString(), Month::last($month)->toDateString()])->delete();
            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                AttendanceDay::query()->insert($chunk);
            }
        });

        $recomputed = 0;
        $late = [];
        if ($closed) {
            $late = $this->lateChanges($month, $before);
        } else {
            foreach ($this->affected($month, $numbers->all()) as $employee) {
                $this->recalculator->estimate($employee, $month);
                $recomputed++;
            }
        }

        SyncLog::query()->create([
            'kind' => 'data', 'month' => $month, 'ok' => true,
            'message' => $closed ? 'Mesiac je uzavretý – ceny sa nezmenili.' : null,
            'employees' => $numbers->count(), 'days' => count($rows),
            'unmatched' => $unmatched, 'late_changes' => $late ?: null, 'ip' => $ip, 'received_at' => now(),
        ]);

        return ['month' => $month, 'employees' => $numbers->count(), 'days' => count($rows), 'unmatched' => $unmatched,
            'recomputed' => $recomputed, 'closed' => $closed, 'late_changes' => $late];
    }

    /** Nightly: refresh the estimate of open months (past/future boundary moves every day). */
    public function recomputeOpenMonths(): int
    {
        $n = 0;
        foreach ([Month::previous(Month::current()), Month::current(), Month::next(Month::current())] as $month) {
            if ($this->closedMonths->isClosed($month) || ! AttendanceDay::query()->whereBetween('date', [Month::first($month)->toDateString(), Month::last($month)->toDateString()])->exists()) {
                continue;
            }
            foreach ($this->affected($month, []) as $employee) {
                $this->recalculator->estimate($employee, $month);
                $n++;
            }
        }

        return $n;
    }

    /** "Synchronizovať teraz": the bridge picks this up on its next poll. */
    public function request(array $months): void
    {
        $this->settings->set(Settings::ATTENDANCE_SYNC_REQUEST, ['at' => now()->toIso8601String(), 'months' => array_values($months)]);
    }

    /** @return array{pending: bool, months: list<string>, requested_at: ?string} */
    public function pending(): array
    {
        $req = $this->settings->get(Settings::ATTENDANCE_SYNC_REQUEST);
        if (! is_array($req) || empty($req['at'])) {
            return ['pending' => false, 'months' => [], 'requested_at' => null];
        }
        $done = collect($req['months'])->every(fn ($m) => SyncLog::query()->where('kind', 'data')->where('month', $m)
            ->where('received_at', '>=', CarbonImmutable::parse($req['at']))->exists());

        return ['pending' => ! $done, 'months' => $done ? [] : $req['months'], 'requested_at' => $req['at']];
    }

    public function lastSuccess(?string $month = null): ?SyncLog
    {
        return SyncLog::query()->where('kind', 'data')->where('ok', true)
            ->when($month, fn ($q) => $q->where('month', $month))->latest('received_at')->first();
    }

    /** Employees to recompute: everyone in ticket/mandatory mode that month + anyone matched in the data. */
    private function affected(string $month, array $numbers)
    {
        $ids = $this->roster->employees($month)->map(fn ($r) => $r['employee']->id)->toBase();
        foreach ($numbers as $n) {
            if ($e = $this->matcher->find($n)) {
                $ids->push($e->id);
            }
        }
        $ids = $ids->merge(AttendanceResult::query()->where('month', $month)->pluck('employee_id'));

        return Employee::query()->whereIn('id', $ids->unique())->orderBy('id')->get();
    }

    /** @return array<int, array{tickets: ?int, mandatory: ?int, cents: int}> employee id → final values */
    private function finalSnapshot(string $month): array
    {
        $out = [];
        foreach ($this->roster->employees($month) as $r) {
            $res = AttendanceResult::query()->where('employee_id', $r['employee']->id)->where('month', $month)->first();
            $out[$r['employee']->id] = ['tickets' => $res?->final_meals, 'mandatory' => $res?->mandatory_final];
        }

        return $out;
    }

    /** Differences between the stored final values and what the new data would give (simulated, nothing saved). */
    private function lateChanges(string $month, array $before): array
    {
        return Simulation::run(function () use ($month, $before) {
            $changes = [];
            foreach ($this->roster->employees($month) as $r) {
                $e = $r['employee'];
                $old = $before[$e->id] ?? ['tickets' => null, 'mandatory' => null];
                $centsBefore = MonthTotals::cents($e->id, $month);
                $this->recalculator->finalize($e, $month, null);
                $res = AttendanceResult::query()->where('employee_id', $e->id)->where('month', $month)->first();
                $new = ['tickets' => $res?->final_meals, 'mandatory' => $res?->mandatory_final];
                $centsAfter = MonthTotals::cents($e->id, $month);
                if ($new === $old && $centsAfter === $centsBefore) {
                    continue;
                }
                $changes[] = ['employee_id' => $e->id, 'name' => $e->fullNameReversed(), 'osobne_cislo' => $e->osobne_cislo,
                    'tickets' => [$old['tickets'], $new['tickets']], 'mandatory' => [$old['mandatory'], $new['mandatory']],
                    'cents' => $centsAfter - $centsBefore];
            }

            return $changes;
        });
    }

    private function validate(array $p): string
    {
        $month = $p['month'] ?? null;
        if (! is_string($month) || ! Month::valid($month) || ! isset($p['employees']) || ! is_array($p['employees'])) {
            throw new InvalidArgumentException('Chýba mesiac (YYYY-MM) alebo zoznam zamestnancov.');
        }
        if (count($p['employees']) > 5000) {
            throw new InvalidArgumentException('Príliš veľa zamestnancov v jednej dávke.');
        }
        $first = Month::first($month)->toDateString();
        $last = Month::last($month)->toDateString();
        foreach ($p['employees'] as $i => $e) {
            if (! is_array($e) || ! isset($e['osobne_cislo']) || ! preg_match('/^\d{1,16}$/', (string) $e['osobne_cislo']) || ! is_array($e['days'] ?? null)) {
                throw new InvalidArgumentException("Zamestnanec #$i: chýba osobne_cislo (číslo) alebo days.");
            }
            foreach ($e['days'] as $d) {
                $date = $d['date'] ?? '';
                if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $first || $date > $last) {
                    throw new InvalidArgumentException("Zamestnanec {$e['osobne_cislo']}: dátum mimo mesiaca $month.");
                }
                foreach (['planned_seconds', 'total_seconds'] as $k) {
                    if (isset($d[$k]) && (! is_int($d[$k]) || $d[$k] < 0 || $d[$k] > 86400 * 2)) {
                        throw new InvalidArgumentException("Zamestnanec {$e['osobne_cislo']}, $date: neplatné $k.");
                    }
                }
            }
        }

        return $month;
    }
}
