<?php

use Symfony\Component\Process\Process;
use Tests\Support\E2e\E2eDatabaseGuard;

/**
 * Build the explicit environment handed to the E2E entrypoints.
 *
 * DB_PORT points at a closed port so a guard regression cannot reach the
 * real PostgreSQL service: the negative checks must fail on the guard
 * message, never on an accidental connection.
 *
 * @param  array<string, string|null>  $overrides
 * @return array<string, string>
 */
function e2eScriptEnvironment(array $overrides = []): array
{
    $environment = getenv();

    $environment['APP_ENV'] = 'e2e';
    $environment['APP_DEBUG'] = 'false';
    $environment['DB_CONNECTION'] = 'pgsql';
    $environment['DB_HOST'] = '127.0.0.1';
    $environment['DB_PORT'] = '1';
    $environment['DB_DATABASE'] = 'homflo_e2e';
    $environment['E2E_ALLOW_DATABASE_RESET'] = '1';
    $environment['E2E_RUN_ID'] = 'e2e-guard-check';
    $environment['E2E_FIXTURE_PASSWORD'] = 'guard-check-password';

    unset($environment['DB_URL']);

    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($environment[$key]);

            continue;
        }

        $environment[$key] = $value;
    }

    return $environment;
}

/**
 * @param  array<string, string>  $environment
 * @return array{0: int|null, 1: string}
 */
function runE2eEntrypoint(string $script, array $environment): array
{
    $process = new Process(
        [PHP_BINARY, dirname(__DIR__, 2).'/tests/Support/'.$script],
        dirname(__DIR__, 2),
        $environment,
        null,
        20
    );

    $process->run();

    return [$process->getExitCode(), $process->getErrorOutput()];
}

test('prepare script rejects a missing reset acknowledgement before any database work', function () {
    [$exitCode, $stderr] = runE2eEntrypoint('e2e_prepare.php', e2eScriptEnvironment([
        E2eDatabaseGuard::RESET_ACKNOWLEDGEMENT => null,
    ]));

    expect($exitCode)->toBe(2)
        ->and($stderr)->toContain('E2E_ALLOW_DATABASE_RESET must be exactly "1"')
        ->and($stderr)->not->toContain('migrate');
});

test('prepare script rejects a wrong reset acknowledgement before any database work', function () {
    [$exitCode, $stderr] = runE2eEntrypoint('e2e_prepare.php', e2eScriptEnvironment([
        E2eDatabaseGuard::RESET_ACKNOWLEDGEMENT => 'true',
    ]));

    expect($exitCode)->toBe(2)
        ->and($stderr)->toContain('E2E_ALLOW_DATABASE_RESET must be exactly "1"')
        ->and($stderr)->not->toContain('migrate');
});

test('prepare script rejects homflo_db before any database work', function () {
    [$exitCode, $stderr] = runE2eEntrypoint('e2e_prepare.php', e2eScriptEnvironment([
        'DB_DATABASE' => 'homflo_db',
    ]));

    expect($exitCode)->toBe(2)
        ->and($stderr)->toContain('DB_DATABASE must be exactly "homflo_e2e"')
        ->and($stderr)->not->toContain('migrate');
});

test('prepare script rejects homflo_test before any database work', function () {
    [$exitCode, $stderr] = runE2eEntrypoint('e2e_prepare.php', e2eScriptEnvironment([
        'DB_DATABASE' => 'homflo_test',
    ]));

    expect($exitCode)->toBe(2)
        ->and($stderr)->toContain('DB_DATABASE must be exactly "homflo_e2e"')
        ->and($stderr)->not->toContain('migrate');
});

test('prepare script rejects a configured DB_URL before any database work', function () {
    [$exitCode, $stderr] = runE2eEntrypoint('e2e_prepare.php', e2eScriptEnvironment([
        'DB_URL' => 'pgsql://homflo:secret@127.0.0.1/homflo_e2e',
    ]));

    expect($exitCode)->toBe(2)
        ->and($stderr)->toContain('DB_URL must not be set')
        ->and($stderr)->not->toContain('migrate');
});

test('serve script refuses to start the API against homflo_db', function () {
    [$exitCode, $stderr] = runE2eEntrypoint('e2e_serve.php', e2eScriptEnvironment([
        'DB_DATABASE' => 'homflo_db',
    ]));

    expect($exitCode)->toBe(2)
        ->and($stderr)->toContain('DB_DATABASE must be exactly "homflo_e2e"');
});

test('database creation refuses any target other than homflo_e2e without connecting', function () {
    expect(fn () => E2eDatabaseGuard::ensureDatabaseExists(['database' => 'homflo_db']))
        ->toThrow(RuntimeException::class, 'Refusing to create a database other than homflo_e2e');
});
