<?php

/**
 * Websupport cron wrapper — runs `php artisan schedule:run`.
 *
 * Websupport WebAdmin cron can only execute a PHP *file* (path relative to the
 * domain folder, no arguments), so it cannot call `artisan schedule:run`
 * directly. This file bootstraps the app exactly like `artisan` does, with the
 * arguments built in.
 *
 * Setup (see README → Cron):
 *   1. Copy this file OUTSIDE the web root, e.g. ~/<domain>/cron/jedalen-schedule.php
 *   2. Set APP_DIR below (absolute, or relative to this file's directory).
 *   3. WebAdmin → Cron: run cron/jedalen-schedule.php every minute with PHP ≥ 8.3.
 *
 * Every run writes storage/logs/cron-last-run.txt (start time, end time, exit code).
 */

// Path to the application directory (the one containing `artisan`).
// Example layout: ~/<domain>/cron/this-file.php  and  ~/apps/jedalen
const APP_DIR = '../../apps/jedalen';

use Illuminate\Foundation\Application;
use Symfony\Component\Console\Input\ArgvInput;

$appDir = str_starts_with(APP_DIR, '/') ? APP_DIR : __DIR__.'/'.APP_DIR;
$appDir = realpath($appDir);

if ($appDir === false || ! is_file($appDir.'/artisan')) {
    fwrite(STDERR, 'websupport-cron: app directory not found: '.APP_DIR.PHP_EOL);
    exit(1);
}

chdir($appDir);
$statusFile = $appDir.'/storage/logs/cron-last-run.txt';
$started = gmdate('c');
@file_put_contents($statusFile, "started {$started} UTC (running)\nphp ".PHP_BINARY.' '.PHP_VERSION.PHP_EOL);

$argv = ['artisan', 'schedule:run'];
$argc = count($argv);
$_SERVER['argv'] = $argv;
$_SERVER['argc'] = $argc;

register_shutdown_function(function () use ($statusFile, $started) {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        @file_put_contents($statusFile, "started {$started} UTC\nFATAL: {$error['message']} in {$error['file']}:{$error['line']}\n", FILE_APPEND);
    }
});

define('LARAVEL_START', microtime(true));

require $appDir.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $appDir.'/bootstrap/app.php';

$status = $app->handleCommand(new ArgvInput($argv));

@file_put_contents($statusFile, "started {$started} UTC\nfinished ".gmdate('c')." UTC\nexit code {$status}\nphp ".PHP_BINARY.' '.PHP_VERSION.PHP_EOL);

exit($status);
