<?php

/**
 * M0 hosting probe — standalone, does NOT need Laravel or Composer.
 *
 * Usage:
 *   1. Upload this single file to the subdomain's web root (e.g. via FTP).
 *   2. Open https://<subdomain>/hosting-probe.php?token=<TOKEN> in a browser.
 *      Optionally add &db_host=..&db_name=..&db_user=.. and enter the DB password
 *      in the form to test the MySQL/MariaDB connection.
 *   3. Run it from SSH / a cron job too:  php hosting-probe.php
 *      (CLI output shows which PHP binary and version cron/SSH actually uses).
 *   4. DELETE THE FILE when done.
 *
 * Change TOKEN below before uploading.
 */
const TOKEN = 'change-me-before-upload';
const MIN_PHP = '8.3.0';           // Laravel 13 requirement

$cli = PHP_SAPI === 'cli';

if (! $cli && (($_GET['token'] ?? '') !== TOKEN || TOKEN === 'change-me-before-upload')) {
    http_response_code(404);
    exit;
}

$results = [];
$add = function (string $check, ?bool $ok, string $detail = '') use (&$results) {
    $results[] = [$check, $ok, $detail];
};

// --- PHP -------------------------------------------------------------------
$add('PHP version >= '.MIN_PHP, version_compare(PHP_VERSION, MIN_PHP, '>='), PHP_VERSION.' ('.PHP_SAPI.')');
$add('PHP binary', null, PHP_BINARY ?: '(unknown)');

$extensions = [
    'ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring', 'openssl', 'pcre',
    'pdo', 'pdo_mysql', 'session', 'tokenizer', 'xml', 'xmlwriter', 'xmlreader',
    'intl',      // Filament, number/date formatting
    'zip',       // XLSX export/import (OpenSpout)
    'sodium',
];
foreach ($extensions as $ext) {
    $add("ext-$ext", extension_loaded($ext));
}
foreach (['gd' => 'PDF images (dompdf)', 'pcntl' => 'only for the concurrency test'] as $ext => $why) {
    $add("ext-$ext (optional: $why)", extension_loaded($ext) ? true : null, extension_loaded($ext) ? '' : 'missing');
}

$disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
foreach (['proc_open', 'proc_get_status', 'exec', 'shell_exec', 'symlink', 'putenv', 'escapeshellarg'] as $fn) {
    $add("function $fn() available", function_exists($fn) && ! in_array($fn, $disabled, true));
}

foreach (['memory_limit', 'max_execution_time', 'upload_max_filesize', 'post_max_size', 'date.timezone', 'open_basedir', 'opcache.enable'] as $ini) {
    $add("ini $ini", null, var_export(ini_get($ini), true));
}

$tz = new DateTimeZone('Europe/Bratislava');
$add('Timezone Europe/Bratislava known', true, (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s T'));

// --- Filesystem --------------------------------------------------------------
$here = __DIR__;
$add('Script directory', null, $here);
$add('Document root', null, $_SERVER['DOCUMENT_ROOT'] ?? '(cli)');
$parent = dirname($here);
$add('Parent directory writable (app outside web root possible)', is_writable($parent), $parent);
$tmp = $here.'/.probe-write-test';
$add('Web root writable', @file_put_contents($tmp, 'x') !== false, $tmp);
@unlink($tmp);

$link = $here.'/.probe-symlink-test';
$canLink = function_exists('symlink') && @symlink($here, $link);
$add('symlink() works', $canLink);
if ($canLink) {
    @unlink($link);
}

// --- Binaries (cron / backups) ----------------------------------------------
if (function_exists('exec') && ! in_array('exec', $disabled, true)) {
    foreach (['php', 'php8.3', 'php8.4', 'php8.5', 'composer', 'mysqldump', 'git', 'gzip'] as $bin) {
        $out = [];
        @exec('command -v '.escapeshellarg($bin).' 2>/dev/null', $out);
        // only "php" is required; the rest is informational
        $add("binary $bin", $out !== [] ? true : ($bin === 'php' ? false : null), $out !== [] ? implode(' ', $out) : 'not found');
    }
}

// --- HTTP / HTTPS -----------------------------------------------------------
if (! $cli) {
    $https = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $add('Request over HTTPS', $https, 'HTTPS='.($_SERVER['HTTPS'] ?? '-').' X-Forwarded-Proto='.($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '-'));
    $add('Server software', null, $_SERVER['SERVER_SOFTWARE'] ?? '?');
    $add('mod_rewrite (apache_get_modules)', function_exists('apache_get_modules') ? in_array('mod_rewrite', apache_get_modules(), true) : null, function_exists('apache_get_modules') ? '' : 'cannot tell (not mod_php) – test with the .htaccess rewrite instead');
    $add('REMOTE_ADDR / X-Forwarded-For', null, ($_SERVER['REMOTE_ADDR'] ?? '').' / '.($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '-'));
}

// --- Database ---------------------------------------------------------------
$db = $cli
    ? ['host' => getenv('DB_HOST') ?: null, 'name' => getenv('DB_DATABASE') ?: null, 'user' => getenv('DB_USERNAME') ?: null, 'pass' => getenv('DB_PASSWORD') ?: '']
    : ['host' => $_POST['db_host'] ?? null, 'name' => $_POST['db_name'] ?? null, 'user' => $_POST['db_user'] ?? null, 'pass' => $_POST['db_pass'] ?? ''];

if ($db['host'] && $db['name'] && $db['user']) {
    try {
        $pdo = new PDO("mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4", $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $add('DB connect', true, $pdo->query('SELECT VERSION()')->fetchColumn());
        $add('DB time_zone / system_time_zone', null, implode(' / ', $pdo->query('SELECT @@time_zone, @@system_time_zone')->fetch(PDO::FETCH_NUM)));
        $add('DB Slovak collation available', (bool) $pdo->query("SHOW COLLATION LIKE 'utf8mb4_slovak_ci'")->fetch());
        $pdo->exec('CREATE TABLE IF NOT EXISTS _probe (id INT PRIMARY KEY, v VARCHAR(10)) ENGINE=InnoDB');
        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO _probe VALUES (1, "ok") ON DUPLICATE KEY UPDATE v = "ok"');
        $pdo->query('SELECT * FROM _probe WHERE id = 1 FOR UPDATE')->fetchAll();
        $pdo->commit();
        $pdo->exec('DROP TABLE _probe');
        $add('DB InnoDB + transactions + SELECT … FOR UPDATE', true);
    } catch (Throwable $e) {
        $add('DB connect', false, $e->getMessage());
    }
} else {
    $add('DB connect', null, $cli ? 'set DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD env vars to test' : 'fill in the form below to test');
}

// --- Output -----------------------------------------------------------------
if ($cli) {
    foreach ($results as [$check, $ok, $detail]) {
        printf("%-4s %-55s %s\n", $ok === null ? 'info' : ($ok ? 'OK' : 'FAIL'), $check, $detail);
    }
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo '<!doctype html><meta charset="utf-8"><title>Hosting probe</title><style>body{font:14px system-ui;margin:2rem}td{padding:2px 8px;border-bottom:1px solid #ddd}.ok{color:green}.fail{color:#b00;font-weight:bold}</style>';
echo '<h1>Hosting probe</h1><table>';
foreach ($results as [$check, $ok, $detail]) {
    $cls = $ok === null ? '' : ($ok ? 'ok' : 'fail');
    $label = $ok === null ? 'info' : ($ok ? 'OK' : 'FAIL');
    echo '<tr><td class="'.$cls.'">'.$label.'</td><td>'.htmlspecialchars($check).'</td><td>'.htmlspecialchars((string) $detail).'</td></tr>';
}
echo '</table><h2>DB test</h2><form method="post"><input name="db_host" placeholder="host" value="'.htmlspecialchars($db['host'] ?? '').'"> <input name="db_name" placeholder="database" value="'.htmlspecialchars($db['name'] ?? '').'"> <input name="db_user" placeholder="user" value="'.htmlspecialchars($db['user'] ?? '').'"> <input name="db_pass" type="password" placeholder="password"> <button>Test</button></form>';
echo '<p><strong>Delete this file when done.</strong></p>';
