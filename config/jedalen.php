<?php

return [
    // Business timezone: "today", cutoffs and displayed times use this zone.
    // Timestamps are stored in UTC (config/app.php 'timezone' stays UTC).
    'timezone' => env('APP_LOCAL_TIMEZONE', 'Europe/Bratislava'),

    // Employee login rate limit (per osobné číslo + IP).
    'login_max_attempts' => 5,
    'login_decay_seconds' => 60,
];
