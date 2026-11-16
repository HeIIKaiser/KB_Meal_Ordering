<?php

namespace Bridge;

/** Settings from the environment (.env via docker compose). */
final class Config
{
    public function __construct(
        public readonly string $dbHost,
        public readonly int $dbPort,
        public readonly string $dbName,
        public readonly string $dbUser,
        public readonly string $dbPassword,
        public readonly string $appUrl,
        public readonly string $token,
        public readonly string $syncTime,
        public readonly int $pollMinutes,
        public readonly string $shiftNameColumn,
    ) {}

    public static function fromEnv(): self
    {
        $get = fn (string $k, ?string $default = null) => ($v = getenv($k)) === false || $v === '' ? $default : $v;

        return new self(
            dbHost: $get('WV_DB_HOST', '192.168.0.3'),
            dbPort: (int) $get('WV_DB_PORT', '3308'),
            dbName: $get('WV_DB_NAME', 'webbox'),
            dbUser: $get('WV_DB_USER', 'meals_ro'),
            dbPassword: (string) $get('WV_DB_PASSWORD', ''),
            appUrl: rtrim((string) $get('APP_URL', ''), '/'),
            token: (string) $get('SYNC_TOKEN', ''),
            syncTime: $get('SYNC_TIME', '02:00'),
            pollMinutes: max(1, (int) $get('POLL_MINUTES', '5')),
            shiftNameColumn: $get('WV_SHIFT_NAME_COLUMN', 'NAME'),
        );
    }

    public function pdo(): \PDO
    {
        return new \PDO(
            "mysql:host={$this->dbHost};port={$this->dbPort};dbname={$this->dbName};charset=utf8mb4",
            $this->dbUser,
            $this->dbPassword,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::ATTR_TIMEOUT => 15],
        );
    }
}
