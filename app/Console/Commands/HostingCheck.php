<?php

namespace App\Console\Commands;

use App\Support\LocalTime;
use App\Support\SchedulerHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * M0 / deployment smoke test. Run on the server after every deploy:
 *   php artisan app:hosting-check
 */
class HostingCheck extends Command
{
    protected $signature = 'app:hosting-check';

    protected $description = 'Check that the hosting environment meets the app requirements';

    private bool $failed = false;

    public function handle(): int
    {
        $this->row('PHP >= 8.3', version_compare(PHP_VERSION, '8.3.0', '>='), PHP_VERSION.' '.PHP_BINARY);

        foreach (['pdo_mysql', 'intl', 'zip', 'mbstring', 'openssl', 'xmlwriter', 'fileinfo', 'sodium'] as $ext) {
            $this->row("ext-$ext", extension_loaded($ext));
        }

        $this->row('APP_KEY set', filled(config('app.key')));
        $this->row('APP_URL is https', str_starts_with((string) config('app.url'), 'https://') || ! app()->isProduction(), (string) config('app.url'));
        $this->row('APP_DEBUG off in production', ! (app()->isProduction() && config('app.debug')), 'env='.app()->environment());
        $this->row('Secure session cookie in production', ! app()->isProduction() || config('session.secure') === true);
        $this->row('app.timezone is UTC', config('app.timezone') === 'UTC', config('app.timezone'));
        $this->row('Local time', true, LocalTime::now()->format('Y-m-d H:i:s T'));

        try {
            $pdo = DB::connection()->getPdo();
            $this->row('DB connection', true, DB::connection()->getDriverName().' '.$pdo->getAttribute(\PDO::ATTR_SERVER_VERSION));
            $this->row('DB migrated', DB::table('migrations')->exists());
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $engine = DB::selectOne("SELECT ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'");
                $this->row('orders table uses InnoDB (row locks)', ($engine->e ?? null) === 'InnoDB', $engine->e ?? 'missing');
            }
        } catch (Throwable $e) {
            $this->row('DB connection', false, $e->getMessage());
        }

        foreach ([storage_path('app'), storage_path('logs'), storage_path('framework/sessions'), base_path('bootstrap/cache')] as $dir) {
            $this->row('writable '.str_replace(base_path().'/', '', $dir), is_writable($dir));
        }

        $this->row('Filament assets published', is_file(public_path('css/filament/filament/app.css')));

        $last = SchedulerHeartbeat::last();
        $this->row(
            'Cron runs schedule:run (heartbeat < 3 min old)',
            $last !== null && $last->gt(now()->subMinutes(3)),
            $last ? 'last: '.LocalTime::toLocal($last)->format('Y-m-d H:i:s').' – '.SchedulerHeartbeat::details() : 'never – cron not set up yet?',
        );

        $this->newLine();
        $this->failed ? $this->error('Some checks FAILED.') : $this->info('All checks passed.');

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function row(string $label, bool $ok, string $detail = ''): void
    {
        $this->failed = $this->failed || ! $ok;
        $this->line(sprintf('%s  %-48s %s', $ok ? '<info>OK  </info>' : '<error>FAIL</error>', $label, $detail));
    }
}
