<?php

namespace Bridge;

/** Main loop: nightly sync of the current + previous month, and "Synchronizovať teraz" requests. */
final class Bridge
{
    public function __construct(
        private Config $config,
        private AppClient $app,
        private ?\PDO $pdo = null,
    ) {}

    public static function log(string $message): void
    {
        fwrite(STDOUT, '['.date('Y-m-d H:i:s').'] '.$message.PHP_EOL);
    }

    /** Checks the schema, then sends the months. Reports failures to the app. */
    public function sync(array $months): bool
    {
        try {
            $pdo = $this->pdo ??= $this->config->pdo();
            if ($problems = SchemaCheck::problems($pdo, $this->config->shiftNameColumn)) {
                throw new \RuntimeException('Schéma WebVisitor sa zmenila: '.implode(' ', $problems));
            }
            $reader = new WebVisitorReader($pdo, $this->config->shiftNameColumn);
            foreach ($months as $month) {
                $payload = (new PayloadBuilder)->build($reader, $month);
                $result = $this->app->sendMonth($payload);
                self::log(sprintf('%s: sent %d people, app stored %d days, unmatched %d%s, PERIOD format %s',
                    $month, count($payload['employees']), $result['days'] ?? 0, count($result['unmatched'] ?? []),
                    ! empty($result['closed']) ? ' (month closed – prices unchanged)' : '', $reader->periodFormat ?? 'n/a'));
            }
            $this->app->status(true, 'Synchronizované: '.implode(', ', $months));

            return true;
        } catch (\Throwable $e) {
            $this->pdo = null; // reconnect next time
            self::log('ERROR: '.$e->getMessage());
            try {
                $this->app->status(false, $e->getMessage());
            } catch (\Throwable $e2) {
                self::log('ERROR reporting status: '.$e2->getMessage());
            }

            return false;
        }
    }

    /** @return list<string> current and previous month */
    public static function defaultMonths(\DateTimeImmutable $now): array
    {
        return [$now->modify('first day of last month')->format('Y-m'), $now->format('Y-m')];
    }

    public function run(): never
    {
        self::log('Bridge started: nightly at '.$this->config->syncTime.', polling every '.$this->config->pollMinutes.' min.');
        $lastNightly = null;
        $lastPoll = 0;
        while (true) {
            $now = new \DateTimeImmutable;
            if ($now->format('H:i') >= $this->config->syncTime && $lastNightly !== $now->format('Y-m-d')) {
                $lastNightly = $now->format('Y-m-d');
                self::log('Nightly sync');
                $this->sync(self::defaultMonths($now));
            }
            if (time() - $lastPoll >= $this->config->pollMinutes * 60) {
                $lastPoll = time();
                try {
                    $pending = $this->app->pending();
                    if (! empty($pending['pending'])) {
                        self::log('Sync requested in the app');
                        $this->sync($pending['months'] ?: self::defaultMonths($now));
                    }
                } catch (\Throwable $e) {
                    self::log('ERROR polling the app: '.$e->getMessage());
                }
            }
            sleep(30);
        }
    }
}
