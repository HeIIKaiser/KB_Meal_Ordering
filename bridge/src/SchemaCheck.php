<?php

namespace Bridge;

/**
 * WebVisitor's schema is undocumented and can change with vendor updates, so the bridge
 * refuses to send anything unless every table/column it reads exists (docs/ATTENDANCE.md §4.4).
 */
final class SchemaCheck
{
    /** @return array<string, list<string>> table → columns read by WebVisitorReader */
    public static function expected(string $shiftNameColumn = 'NAME'): array
    {
        return [
            'sys_employment' => ['PK_', 'PERSONALNUMBER'],
            'vis_attdaypart' => ['PK_', 'DATE', 'FUND', 'PLANSTART', 'PLANEND', 'STATUS', 'VIS_WORKINTERVAL_PK_', 'SYS_EMPLOYMENT_PK_'],
            'vis_daycumulative' => ['VIS_ATTDAYPART_PK_', 'TYPE', 'TYPE_PK_', 'VALUE'],
            'vis_workinterval' => ['PK_', $shiftNameColumn],
            'vis_monthatt' => ['SYS_EMPLOYMENT_PK_', 'PERIOD', 'LOCK'],
        ];
    }

    /** @return list<string> problems (empty = OK) */
    public static function problems(\PDO $pdo, string $shiftNameColumn = 'NAME'): array
    {
        $problems = [];
        foreach (self::expected($shiftNameColumn) as $table => $columns) {
            try {
                // Works on MySQL and SQLite (tests); LIMIT 0 reads no data.
                $stmt = $pdo->query('SELECT * FROM `'.$table.'` LIMIT 0');
                $present = [];
                for ($i = 0; $i < $stmt->columnCount(); $i++) {
                    $present[] = strtoupper((string) $stmt->getColumnMeta($i)['name']);
                }
            } catch (\PDOException $e) {
                $problems[] = "Tabuľka $table chýba alebo nie je prístupná.";

                continue;
            }
            foreach ($columns as $c) {
                if (! in_array(strtoupper($c), $present, true)) {
                    $problems[] = "V tabuľke $table chýba stĺpec $c.";
                }
            }
        }

        return $problems;
    }
}
