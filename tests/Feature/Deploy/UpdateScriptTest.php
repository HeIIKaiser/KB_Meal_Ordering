<?php

use Symfony\Component\Process\Process;

/*
 * deploy/update.sh picks the PHP binary, checks composer.lock against it before changing
 * anything, and says which step failed. Full runs use fakes: `php` runs real PHP except for
 * `artisan …` (only logged), `git` and `composer` are logged stubs — nothing real changes.
 */

function fakeBin(): string
{
    $dir = sys_get_temp_dir().'/update-test-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $log = "$dir/calls.log";
    $realPhp = PHP_BINARY;

    file_put_contents("$dir/php", <<<SH
#!/usr/bin/env bash
if [ "\$1" = "artisan" ]; then echo "php \$*" >> "$log"; exit 0; fi
exec "$realPhp" "\$@"
SH);
    file_put_contents("$dir/git", <<<SH
#!/usr/bin/env bash
echo "git \$*" >> "$log"
if [ "\$1" = "log" ]; then echo "abc1234 Test commit"; fi
exit 0
SH);
    chmod("$dir/php", 0755);
    chmod("$dir/git", 0755);

    return $dir;
}

/** A fake composer (run by PHP like the real one): $failOn = sub-command that fails. */
function fakeComposer(string $dir, ?string $failOn = null): void
{
    $log = "$dir/calls.log";
    file_put_contents("$dir/composer", <<<PHP
#!/usr/bin/env php
<?php
file_put_contents('$log', 'composer '.implode(' ', array_slice(\$argv, 1)).PHP_EOL, FILE_APPEND);
if ((\$argv[1] ?? '') === '$failOn') { fwrite(STDERR, "php 8.6.0 failed (requires php <8.6)\\n"); exit(2); }
exit(0);
PHP);
    chmod("$dir/composer", 0755);
}

function runUpdate(string $bin, array $args = [], array $env = []): Process
{
    $process = new Process(['bash', base_path('deploy/update.sh'), ...$args], base_path(), [
        'PATH' => $bin.':'.getenv('PATH'),
        'UPDATE_PHP_DIR' => $bin.'/none',
        'PHP' => false,
        ...$env,
    ]);
    $process->setTimeout(60)->run();

    return $process;
}

function calls(string $bin): string
{
    return is_file("$bin/calls.log") ? file_get_contents("$bin/calls.log") : '';
}

it('--check only checks PHP and composer.lock with the real Composer', function () {
    $p = runUpdate(fakeBin(), ['--check'], ['PHP' => PHP_BINARY]);

    expect($p->getExitCode())->toBe(0)
        ->and($p->getOutput())->toContain('== PHP: '.PHP_BINARY.' ('.PHP_VERSION.') – env PHP')
        ->and($p->getOutput())->toContain('Check OK – nothing was changed');
})->skip(fn () => ! trim((string) shell_exec('command -v composer')), 'composer not installed');

it('stops before backup, maintenance mode and git when PHP does not satisfy composer.lock', function () {
    $bin = fakeBin();
    fakeComposer($bin, failOn: 'check-platform-reqs');
    $p = runUpdate($bin);

    expect($p->getExitCode())->toBe(1)
        ->and($p->getErrorOutput())->toContain('does not satisfy composer.lock')
        ->and($p->getErrorOutput())->toContain('Nothing was changed: no backup, no maintenance mode, no git pull')
        ->and(calls($bin))->toContain('composer check-platform-reqs --lock --no-dev')
        ->not->toContain('artisan')
        ->not->toContain('git ');
});

it('runs Composer with the chosen PHP and finishes the update in order', function () {
    $bin = fakeBin();
    fakeComposer($bin);
    $p = runUpdate($bin, env: ['PHP' => "$bin/php"]);

    expect($p->getExitCode())->toBe(0);
    $order = array_values(array_filter(explode("\n", calls($bin))));
    expect($order[0])->toBe('composer check-platform-reqs --lock --no-dev')
        ->and($order[1])->toBe('php artisan app:backup')
        ->and($order[2])->toStartWith('php artisan down')
        ->and($order[3])->toBe('git fetch origin main')
        ->and(calls($bin))->toContain("composer install --no-dev --optimize-autoloader --no-interaction\nphp artisan migrate --force")
        ->and(end($order))->toBe('php artisan up');
});

it('says which step failed and brings the site back up', function () {
    $bin = fakeBin();
    fakeComposer($bin, failOn: 'install');
    $p = runUpdate($bin, env: ['PHP' => "$bin/php"]);

    expect($p->getExitCode())->not->toBe(0)
        ->and($p->getErrorOutput())->toContain('Update FAILED at step: composer install')
        ->and($p->getErrorOutput())->toContain('State: NEW code (abc1234 Test commit), but dependencies, database and caches NOT updated yet')
        ->and(calls($bin))->not->toContain('artisan migrate')
        ->and(trim(calls($bin)))->toEndWith('php artisan up');
});

it('re-checks the NEW composer.lock after the pull and stops before composer install', function () {
    $bin = fakeBin();
    // first check passes, second (after git pull) fails
    file_put_contents("$bin/composer", <<<PHP
#!/usr/bin/env php
<?php
file_put_contents('$bin/calls.log', 'composer '.implode(' ', array_slice(\$argv, 1)).PHP_EOL, FILE_APPEND);
if ((\$argv[1] ?? '') === 'check-platform-reqs' && substr_count(file_get_contents('$bin/calls.log'), 'check-platform-reqs') > 1) { exit(2); }
exit(0);
PHP);
    chmod("$bin/composer", 0755);
    $p = runUpdate($bin, env: ['PHP' => "$bin/php"]);

    expect($p->getExitCode())->not->toBe(0)
        ->and($p->getErrorOutput())->toContain('Update FAILED at step: platform check of the new composer.lock')
        ->and(calls($bin))->not->toContain('composer install');
});

it('prefers /usr/bin/php8.5, falls back to php, and env PHP wins', function () {
    $bin = fakeBin();
    fakeComposer($bin);
    mkdir("$bin/usr-bin");
    copy("$bin/php", "$bin/usr-bin/php8.5");
    chmod("$bin/usr-bin/php8.5", 0755);

    expect(runUpdate($bin, ['--check'], ['UPDATE_PHP_DIR' => "$bin/usr-bin"])->getOutput())->toContain("== PHP: $bin/usr-bin/php8.5 (".PHP_VERSION.') – preferred (php8.5)')
        ->and(runUpdate($bin, ['--check'])->getOutput())->toContain('– default php ('.$bin.'/none/php8.5 not found)')
        ->and(runUpdate($bin, ['--check'], ['UPDATE_PHP_DIR' => "$bin/usr-bin", 'PHP' => "$bin/php"])->getOutput())->toContain("== PHP: $bin/php (".PHP_VERSION.') – env PHP');
});

it('rejects unknown arguments', function () {
    expect(runUpdate(fakeBin(), ['--foo'])->getExitCode())->toBe(2);
});
