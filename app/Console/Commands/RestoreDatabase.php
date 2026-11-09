<?php

namespace App\Console\Commands;

use App\Domain\Backup\DatabaseBackup;
use Illuminate\Console\Command;

/**
 * Restore a backup made by app:backup. REPLACES THE WHOLE DATABASE.
 * Makes a safety backup of the current state first.
 *
 *   php artisan app:backup-restore                       (latest)
 *   php artisan app:backup-restore jedalen_2026-11-10_023000.sql.gz
 */
class RestoreDatabase extends Command
{
    protected $signature = 'app:backup-restore {file? : file name in storage/app/private/backups or a full path} {--force : do not ask}';

    protected $description = 'Restore the database from a backup (replaces all data)';

    public function handle(DatabaseBackup $backup): int
    {
        $file = $this->argument('file');
        $path = match (true) {
            $file === null => $backup->latest()['path'] ?? null,
            is_file($file) => $file,
            default => $backup->find($file),
        };

        if (! $path) {
            $this->error('Backup not found.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Restore '.basename($path).'? ALL current data will be replaced.')) {
            return self::FAILURE;
        }

        $safety = $backup->create();
        $this->line('Safety backup of the current state: '.basename($safety));

        $this->call('down', ['--retry' => 60]);
        try {
            $n = $backup->restore($path);
        } finally {
            $this->call('up');
        }

        $this->info('Restored '.basename($path)." ($n statements).");

        return self::SUCCESS;
    }
}
