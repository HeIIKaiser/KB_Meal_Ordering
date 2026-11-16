<?php

namespace Bridge;

/**
 * Turns WebVisitor rows into the app payload (docs/ATTENDANCE.md §4.2): one entry per
 * PERSONALNUMBER and day, all employments of the person merged by date:
 *   planned_seconds = Σ (PLANEND − PLANSTART) of employments with FUND > 0
 *   total_seconds   = Σ totalWork ("Celkom" — never acceptedWork)
 *   absences        = sys_kodd codes of break rows, except 1 (Obed)
 */
final class PayloadBuilder
{
    public const LUNCH_BREAK = 1;

    public function build(WebVisitorReader $reader, string $month, ?\DateTimeImmutable $now = null): array
    {
        return $this->fromRows($month, $reader->days($month), $reader->cumulative($month), $reader->locks($month), $now);
    }

    public function fromRows(string $month, array $days, array $cumulative, array $locks, ?\DateTimeImmutable $now = null): array
    {
        $byPart = [];
        foreach ($cumulative as $c) {
            $pk = (string) $c['pk'];
            $value = (int) $c['value'];
            if ($c['type'] === 'totalWork') {
                $byPart[$pk]['total'] = ($byPart[$pk]['total'] ?? 0) + $value;
            } elseif ($c['type'] === 'break' && (int) $c['code'] !== self::LUNCH_BREAK && $value > 0) {
                $byPart[$pk]['absences'][] = (int) $c['code'];
            }
        }

        $people = [];
        foreach ($days as $d) {
            $pn = (string) (int) $d['pn'];
            $date = self::date((string) $d['day']);
            $fund = (int) $d['fund'];
            $planned = 0;
            if ($fund > 0) {
                $span = (int) $d['pe'] - (int) $d['ps'];
                $planned = $d['ps'] !== null && $d['pe'] !== null && $span > 0 ? $span : $fund;
            }
            $part = $byPart[(string) $d['pk']] ?? [];

            $day = &$people[$pn]['days'][$date];
            $day ??= ['date' => $date, 'planned_seconds' => 0, 'plan_name' => null, 'total_seconds' => 0, 'absences' => [], 'status' => null];
            $day['planned_seconds'] += $planned;
            if ($fund > 0 && $d['plan_name'] !== null && $d['plan_name'] !== '') {
                $day['plan_name'] = $day['plan_name'] === null ? (string) $d['plan_name'] : $day['plan_name'].' + '.$d['plan_name'];
            }
            $day['total_seconds'] += (int) ($part['total'] ?? 0);
            $day['absences'] = array_values(array_unique([...$day['absences'], ...($part['absences'] ?? [])]));
            $day['status'] = $d['st'] === null ? $day['status'] : max((int) $d['st'], (int) $day['status']);
            unset($day);

            $people[$pn]['employments'][(string) $d['emp']] = true;
        }

        ksort($people, SORT_NATURAL);
        $employees = [];
        foreach ($people as $pn => $p) {
            ksort($p['days']);
            $employments = array_keys($p['employments']);
            $employees[] = [
                'osobne_cislo' => (string) $pn,
                'month_locked' => $employments !== [] && count(array_filter($employments, fn ($e) => $locks[$e] ?? false)) === count($employments),
                'days' => array_values($p['days']),
            ];
        }

        return [
            'month' => $month,
            'generated_at' => ($now ?? new \DateTimeImmutable)->format(DATE_ATOM),
            'employees' => $employees,
        ];
    }

    /** "20261001" (or 20261001) → "2026-10-01" */
    public static function date(string $yyyymmdd): string
    {
        $d = preg_replace('/\D/', '', $yyyymmdd);

        return substr($d, 0, 4).'-'.substr($d, 4, 2).'-'.substr($d, 6, 2);
    }
}
