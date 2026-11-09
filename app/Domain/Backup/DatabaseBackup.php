<?php

namespace App\Domain\Backup;

use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Gzipped SQL dump written in pure PHP (no mysqldump/exec needed on shared hosting).
 *
 *  - Files: storage/app/private/backups/jedalen_YYYY-MM-DD_HHMMSS.sql.gz (local time).
 *    Never reachable from the web (no route into private storage).
 *  - Written as "<name>.part" and renamed when complete, so an offsite pull never
 *    copies a half-written file; the last line is "-- END OF BACKUP".
 *  - One SQL statement per line (newlines inside values are escaped), so the file can be
 *    restored with `php artisan app:backup-restore`, the mysql CLI or phpMyAdmin.
 *  - Tables with throw-away data (sessions, cache, jobs) are dumped without rows.
 */
class DatabaseBackup
{
    public const PREFIX = 'jedalen_';

    public const SUFFIX = '.sql.gz';

    public const END_MARKER = '-- END OF BACKUP';

    /** Structure only, no rows. */
    private const SKIP_DATA = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

    private const ROWS_PER_INSERT = 200;

    public function directory(): string
    {
        $dir = config('jedalen.backup_path') ?: storage_path('app/private/backups');
        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create $dir");
        }

        return $dir;
    }

    /** @return string full path of the new backup */
    public function create(): string
    {
        $name = self::PREFIX.LocalTime::now()->format('Y-m-d_His').self::SUFFIX;
        $path = $this->directory().'/'.$name;
        $part = $path.'.part';

        $gz = gzopen($part, 'wb6');
        if ($gz === false) {
            throw new RuntimeException("Cannot write $part");
        }

        try {
            $this->dump(fn (string $line) => gzwrite($gz, $line."\n"));
        } catch (\Throwable $e) {
            gzclose($gz);
            @unlink($part);
            throw $e;
        }

        gzclose($gz);
        rename($part, $path);
        @chmod($path, 0640);

        return $path;
    }

    /**
     * Delete backups older than $keepDays, but always keep the $keepAtLeast newest ones.
     *
     * @return int number of deleted files
     */
    public function prune(int $keepDays = 30, int $keepAtLeast = 7): int
    {
        $cutoff = CarbonImmutable::now()->subDays($keepDays)->getTimestamp();
        $deleted = 0;

        foreach ($this->list()->slice($keepAtLeast) as $file) {
            if ($file['mtime'] < $cutoff && @unlink($file['path'])) {
                $deleted++;
            }
        }

        // leftovers of interrupted runs
        foreach (glob($this->directory().'/*.part') ?: [] as $part) {
            if (filemtime($part) < time() - 6 * 3600) {
                @unlink($part);
            }
        }

        return $deleted;
    }

    /** @return Collection<int, array{name: string, path: string, size: int, mtime: int}> newest first */
    public function list(): Collection
    {
        return collect(glob($this->directory().'/'.self::PREFIX.'*'.self::SUFFIX) ?: [])
            ->map(fn (string $p) => ['name' => basename($p), 'path' => $p, 'size' => filesize($p), 'mtime' => filemtime($p)])
            ->sortByDesc('name')
            ->values();
    }

    public function latest(): ?array
    {
        return $this->list()->first();
    }

    /** Resolve a file name from the UI safely (no path traversal). */
    public function find(string $name): ?string
    {
        if (! preg_match('/^'.preg_quote(self::PREFIX, '/').'\d{4}-\d{2}-\d{2}_\d{6}'.preg_quote(self::SUFFIX, '/').'$/', $name)) {
            return null;
        }
        $path = $this->directory().'/'.$name;

        return is_file($path) ? $path : null;
    }

    /** Restore a backup file into the current database (all tables are dropped and recreated). */
    public function restore(string $path): int
    {
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            throw new RuntimeException("Cannot read $path");
        }

        $lines = [];
        $complete = false;
        while (($line = gzgets($gz)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === self::END_MARKER) {
                $complete = true;
            }
            if ($line !== '' && ! str_starts_with($line, '--')) {
                $lines[] = $line;
            }
        }
        gzclose($gz);

        if (! $complete) {
            throw new RuntimeException('Záloha je neúplná (chýba koncová značka) – neobnovujem.');
        }

        $db = DB::connection();
        $count = 0;
        foreach ($lines as $statement) {
            $db->unprepared($statement);
            $count++;
        }

        return $count;
    }

    // ------------------------------------------------------------------------

    private function dump(callable $write): void
    {
        $db = DB::connection();
        $driver = $db->getDriverName();
        $mysql = in_array($driver, ['mysql', 'mariadb'], true);
        $pdo = $db->getPdo();
        $q = fn (string $id) => $mysql ? '`'.str_replace('`', '``', $id).'`' : '"'.str_replace('"', '""', $id).'"';

        $tables = $mysql
            ? collect($db->select('SHOW FULL TABLES WHERE Table_type = ?', ['BASE TABLE']))->map(fn ($r) => array_values((array) $r)[0])
            : collect($db->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"))->pluck('name');

        $write('-- Jedáleň database backup');
        $write('-- created '.LocalTime::now()->toIso8601String().' | driver '.$driver.' | database '.$db->getDatabaseName().' | tables '.$tables->count());
        $write($mysql ? 'SET NAMES utf8mb4;' : 'PRAGMA foreign_keys = OFF;');
        if ($mysql) {
            $write('SET FOREIGN_KEY_CHECKS = 0;');
            $write("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';");
        }

        foreach ($tables as $table) {
            $create = $mysql
                ? array_values((array) $db->selectOne('SHOW CREATE TABLE '.$q($table)))[1]
                : $db->selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])->sql;

            $write('');
            $write('-- table '.$table);
            $write('DROP TABLE IF EXISTS '.$q($table).';');
            $write(preg_replace('/\s*\R\s*/', ' ', $create).';');

            if (! $mysql) {
                foreach ($db->select("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$table]) as $idx) {
                    $write(preg_replace('/\s*\R\s*/', ' ', $idx->sql).';');
                }
            }

            if (in_array($table, self::SKIP_DATA, true)) {
                continue;
            }

            $batch = [];
            $columns = null;
            foreach ($db->table($table)->cursor() as $row) {
                $row = (array) $row;
                $columns ??= '('.implode(', ', array_map($q, array_keys($row))).')';
                $batch[] = '('.implode(', ', array_map(fn ($v) => $this->value($v, $pdo, $mysql), $row)).')';

                if (count($batch) >= self::ROWS_PER_INSERT) {
                    $write('INSERT INTO '.$q($table).' '.$columns.' VALUES '.implode(', ', $batch).';');
                    $batch = [];
                }
            }
            if ($batch) {
                $write('INSERT INTO '.$q($table).' '.$columns.' VALUES '.implode(', ', $batch).';');
            }
        }

        $write('');
        $write($mysql ? 'SET FOREIGN_KEY_CHECKS = 1;' : 'PRAGMA foreign_keys = ON;');
        $write(self::END_MARKER);
    }

    /** SQL literal on a single line. */
    private function value(mixed $v, \PDO $pdo, bool $mysql): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }

        $quoted = $pdo->quote((string) $v);

        return $mysql
            ? str_replace(["\r", "\n"], ['\\r', '\\n'], $quoted)
            : str_replace(["\r", "\n"], ["' || char(13) || '", "' || char(10) || '"], $quoted);
    }
}
