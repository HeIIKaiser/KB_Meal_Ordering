<?php

namespace Bridge;

/**
 * All SQL against the WebVisitor database (read-only user meals_ro). Reads one month:
 * the planned/attended day rows of every employment, the daily totals and absences,
 * and the month lock. No names leave this class.
 */
final class WebVisitorReader
{
    /** Format of vis_monthatt.PERIOD seen in the data (logged once). */
    public ?string $periodFormat = null;

    public function __construct(
        private \PDO $pdo,
        private string $shiftNameColumn = 'NAME',
    ) {}

    /** @return list<array{pn: string, emp: string, pk: string, day: string, fund: int, ps: ?int, pe: ?int, st: ?int, plan_name: ?string}> */
    public function days(string $month): array
    {
        [$from, $to] = self::range($month);
        $name = '`'.str_replace('`', '', $this->shiftNameColumn).'`';
        $stmt = $this->pdo->prepare(
            'SELECT e.`PERSONALNUMBER` AS pn, e.`PK_` AS emp, d.`PK_` AS pk, d.`DATE` AS day, d.`FUND` AS fund,
                    d.`PLANSTART` AS ps, d.`PLANEND` AS pe, d.`STATUS` AS st, w.'.$name.' AS plan_name
               FROM `vis_attdaypart` d
               JOIN `sys_employment` e ON e.`PK_` = d.`SYS_EMPLOYMENT_PK_`
          LEFT JOIN `vis_workinterval` w ON w.`PK_` = d.`VIS_WORKINTERVAL_PK_`
              WHERE d.`DATE` BETWEEN :from AND :to AND e.`PERSONALNUMBER` IS NOT NULL'
        );
        $stmt->execute(['from' => $from, 'to' => $to]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** totalWork and break rows of the month's day parts. @return list<array{pk: string, type: string, code: ?int, value: int}> */
    public function cumulative(string $month): array
    {
        [$from, $to] = self::range($month);
        $stmt = $this->pdo->prepare(
            "SELECT c.`VIS_ATTDAYPART_PK_` AS pk, c.`TYPE` AS type, c.`TYPE_PK_` AS code, c.`VALUE` AS value
               FROM `vis_daycumulative` c
               JOIN `vis_attdaypart` d ON d.`PK_` = c.`VIS_ATTDAYPART_PK_`
              WHERE d.`DATE` BETWEEN :from AND :to AND c.`TYPE` IN ('totalWork', 'break')"
        );
        $stmt->execute(['from' => $from, 'to' => $to]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Employments whose attendance month is locked. PERIOD is matched by its digits, so
     * both "202610" and "2026-10-01" work. `LOCK` is a reserved word in MySQL → backticks.
     *
     * @return array<string, bool> employment PK → locked
     */
    public function locks(string $month): array
    {
        $prefix = str_replace('-', '', $month);
        $out = [];
        foreach ($this->pdo->query('SELECT m.`SYS_EMPLOYMENT_PK_` AS emp, m.`PERIOD` AS period, m.`LOCK` AS locked FROM `vis_monthatt` m') as $r) {
            $digits = preg_replace('/\D/', '', (string) $r['period']);
            if (! str_starts_with($digits, $prefix)) {
                continue;
            }
            $this->periodFormat ??= strlen($digits) === 6 ? 'YYYYMM' : 'date ('.$r['period'].')';
            $out[(string) $r['emp']] = (bool) (int) $r['locked'];
        }

        return $out;
    }

    /** @return array{0: string, 1: string} first and last day as YYYYMMDD */
    public static function range(string $month): array
    {
        $first = \DateTimeImmutable::createFromFormat('!Y-m-d', $month.'-01');

        return [$first->format('Ymd'), $first->modify('last day of this month')->format('Ymd')];
    }
}
