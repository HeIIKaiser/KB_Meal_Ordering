<?php

return [
    // Business timezone: "today", cutoffs and displayed times use this zone.
    // Timestamps are stored in UTC (config/app.php 'timezone' stays UTC).
    'timezone' => env('APP_LOCAL_TIMEZONE', 'Europe/Bratislava'),

    // Employee login rate limits:
    //  - per osobné číslo + IP (typos on one phone): 5 failures / 60 s
    //  - per osobné číslo from ANY IP (distributed PIN guessing): 10 failures / 15 min
    'login_max_attempts' => 5,
    'login_decay_seconds' => 60,
    'login_max_attempts_per_number' => 10,
    'login_decay_seconds_per_number' => 15 * 60,

    // Database backups (php artisan app:backup, nightly 02:30). Default: storage/app/private/backups
    'backup_path' => env('BACKUP_PATH'),

    // Reverse proxies whose X-Forwarded-* headers are trusted (comma-separated IPs/CIDRs, or "*").
    // Leave empty unless the hosting puts a proxy in front of PHP (then rate limits and audit
    // would otherwise see the proxy's IP). Check with: php artisan app:hosting-check (web) / support.
    'trusted_proxies' => env('TRUSTED_PROXIES'),
];
