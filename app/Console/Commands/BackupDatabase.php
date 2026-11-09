<?php

namespace App\Console\Commands;

use App\Domain\Backup\DatabaseBackup;
use Illuminate\Console\Command;

/** Nightly via the scheduler; can be run any time: php artisan app:backup */
class BackupDatabase extends Command
{
    protected $signature = 'app:backup {--keep-days=30 : delete backups older than this (the 7 newest are always kept)}';

    protected $description = 'Create a gzipped SQL backup of the database and rotate old ones';

    public function handle(DatabaseBackup $backup): int
    {
        $path = $backup->create();
        $deleted = $backup->prune((int) $this->option('keep-days'));

        $this->info('Backup: '.$path.' ('.number_format(filesize($path) / 1024, 0, ',', ' ').' kB)');
        if ($deleted) {
            $this->line("Deleted $deleted old backup(s).");
        }

        return self::SUCCESS;
    }
}
