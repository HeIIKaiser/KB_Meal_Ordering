<?php

use Bridge\Bridge;
use Bridge\PayloadBuilder;
use Bridge\SchemaCheck;
use Bridge\WebVisitorReader;

/*
 * The attendance bridge (bridge/, standalone PHP) against a fixture with the real
 * WebVisitor table/column names (docs/ATTENDANCE.md §3). Runs on SQLite in memory and,
 * with DB_CONNECTION=mysql, also on MySQL/MariaDB (where `LOCK` must be quoted).
 */

spl_autoload_register(function (string $class) {
    if (str_starts_with($class, 'Bridge\\')) {
        require_once __DIR__.'/../../../bridge/src/'.substr($class, 7).'.php';
    }
});

const WV_TABLES = ['vis_monthatt', 'vis_daycumulative', 'vis_attdaypart', 'vis_workinterval', 'sys_kodd', 'sys_employment'];

function webVisitorFixture(PDO $pdo, bool $withLockColumn = true): void
{
    foreach (WV_TABLES as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `$t`");
    }
    $pdo->exec('CREATE TABLE `sys_employment` (`PK_` INT PRIMARY KEY, `PERSONALNUMBER` INT, `CODE` VARCHAR(20), `DATEBEGIN` INT, `DATEEND` INT)');
    $pdo->exec('CREATE TABLE `vis_workinterval` (`PK_` INT PRIMARY KEY, `NAME` VARCHAR(40), `START` INT, `END` INT)');
    $pdo->exec('CREATE TABLE `sys_kodd` (`PK_` INT PRIMARY KEY, `NAME` VARCHAR(40))');
    $pdo->exec('CREATE TABLE `vis_attdaypart` (`PK_` INT PRIMARY KEY, `DATE` INT, `FUND` INT, `PLANSTART` BIGINT, `PLANEND` BIGINT, `STATUS` INT, `VIS_WORKINTERVAL_PK_` INT, `SYS_EMPLOYMENT_PK_` INT)');
    $pdo->exec('CREATE TABLE `vis_daycumulative` (`PK_` INT PRIMARY KEY, `VIS_ATTDAYPART_PK_` INT, `TYPE` VARCHAR(30), `TYPE_PK_` INT, `VALUE` INT)');
    $pdo->exec('CREATE TABLE `vis_monthatt` (`PK_` INT PRIMARY KEY, `SYS_EMPLOYMENT_PK_` INT, `PERIOD` VARCHAR(10), '.($withLockColumn ? '`LOCK` INT, ' : '').'`LOCKTYPE` INT, `LOCK_DATETIME` INT)');

    $pdo->exec("INSERT INTO `sys_employment` VALUES (1, 29, 'A', 20200101, NULL), (2, 29, 'B', 20250101, NULL), (3, 30, 'C', 20200101, NULL)");
    $pdo->exec("INSERT INTO `vis_workinterval` VALUES (10, 'Kuch 12H', 0, 0), (11, '0H', 0, 0)");
    $pdo->exec("INSERT INTO `sys_kodd` VALUES (1, 'Obed'), (2, 'Dovolenka'), (6, 'PN')");
    $t = strtotime('2026-10-01 06:00:00 UTC');
    $pdo->exec('INSERT INTO `vis_attdaypart` VALUES '
        ."(100, 20261001, 43200, $t, ".($t + 43200).', 1, 10, 1),'   // person 29, employment 1: 12 h shift
        .'(101, 20261001, 0, NULL, NULL, 1, 11, 2),'                  // person 29, employment 2: day off, but worked 1 h
        ."(102, 20261002, 43200, $t, ".($t + 43200).', 0, 10, 1),'   // vacation
        .'(103, 20261003, 0, NULL, NULL, 0, 11, 1),'                  // day off
        .'(104, 20261101, 43200, 0, 43200, 0, 10, 1),'                // other month
        .'(200, 20261001, 28800, NULL, NULL, 1, 10, 3)');             // person 30: FUND without plan times
    $pdo->exec("INSERT INTO `vis_daycumulative` VALUES (1, 100, 'totalWork', NULL, 51720), (2, 100, 'acceptedWork', NULL, 48120), (3, 100, 'break', 1, 1800),"
        ."(4, 101, 'totalWork', NULL, 3600), (5, 102, 'break', 2, 43200), (6, 102, 'totalWork', NULL, 0), (7, 200, 'totalWork', NULL, 30000), (8, 104, 'totalWork', NULL, 99)");
    $pdo->exec('INSERT INTO `vis_monthatt` VALUES '.($withLockColumn
        ? "(1, 1, '202610', 1, 1, 0), (2, 2, '202610', 0, 0, 0), (3, 3, '2026-10-01', 1, 1, 0), (4, 3, '202609', 0, 0, 0)"
        : "(1, 1, '202610', 1, 0)"));
}

/** SQLite always; MySQL/MariaDB when the suite runs with DB_CONNECTION=mysql. */
function bridgeConnections(): array
{
    $out = ['sqlite' => fn () => new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC])];
    if (getenv('DB_CONNECTION') === 'mysql') {
        $out['mysql'] = fn () => new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306', getenv('DB_DATABASE')),
            getenv('DB_USERNAME'), getenv('DB_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
    }

    return $out;
}

foreach (bridgeConnections() as $driver => $connect) {
    describe($driver, function () use ($connect) {
        beforeEach(function () use ($connect) {
            $this->pdo = $connect();
            webVisitorFixture($this->pdo);
        });

        afterEach(function () {
            foreach (WV_TABLES as $t) {
                $this->pdo->exec("DROP TABLE IF EXISTS `$t`");
            }
        });

        it('builds the payload: merges employments, uses totalWork, drops Obed, reads the lock', function () {
            $reader = new WebVisitorReader($this->pdo);
            $payload = (new PayloadBuilder)->build($reader, '2026-10', new DateTimeImmutable('2026-11-16T02:00:00+01:00'));

            expect($payload['month'])->toBe('2026-10')
                ->and(array_column($payload['employees'], 'osobne_cislo'))->toBe(['29', '30']);

            [$p29, $p30] = $payload['employees'];
            expect($p29['month_locked'])->toBeFalse() // employment 2 not locked
                ->and($p29['days'])->toBe([
                    ['date' => '2026-10-01', 'planned_seconds' => 43200, 'plan_name' => 'Kuch 12H', 'total_seconds' => 51720 + 3600, 'absences' => [], 'status' => 1],
                    ['date' => '2026-10-02', 'planned_seconds' => 43200, 'plan_name' => 'Kuch 12H', 'total_seconds' => 0, 'absences' => [2], 'status' => 0],
                    ['date' => '2026-10-03', 'planned_seconds' => 0, 'plan_name' => null, 'total_seconds' => 0, 'absences' => [], 'status' => 0],
                ])
                ->and($p30['month_locked'])->toBeTrue() // PERIOD as a date works too
                ->and($p30['days'][0])->toMatchArray(['planned_seconds' => 28800, 'total_seconds' => 30000])
                ->and($reader->periodFormat)->toBe('YYYYMM');
        });

        it('passes the schema check, and fails it when a column is missing', function () {
            expect(SchemaCheck::problems($this->pdo))->toBe([]);

            webVisitorFixture($this->pdo, withLockColumn: false);
            expect(SchemaCheck::problems($this->pdo))->toBe(['V tabuľke vis_monthatt chýba stĺpec LOCK.']);

            $this->pdo->exec('DROP TABLE `vis_daycumulative`');
            expect(SchemaCheck::problems($this->pdo))->toContain('Tabuľka vis_daycumulative chýba alebo nie je prístupná.');
        });
    });
}

it('sends no names: only numbers, dates and seconds', function () {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    webVisitorFixture($pdo);
    $json = json_encode((new PayloadBuilder)->build(new WebVisitorReader($pdo), '2026-10'));

    expect(array_keys(json_decode($json, true)['employees'][0]))->toBe(['osobne_cislo', 'month_locked', 'days'])
        ->and($json)->not->toContain('Dovolenka');
});

it('defaults to the previous and the current month', function () {
    expect(Bridge::defaultMonths(new DateTimeImmutable('2026-01-15')))->toBe(['2025-12', '2026-01']);
});
