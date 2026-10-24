<?php

use Tests\Support\E2e\E2eDatabaseGuard;

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function e2eGuardEnvironment(array $overrides = []): array
{
    return array_merge([
        'APP_ENV' => 'e2e',
        'DB_CONNECTION' => 'pgsql',
        'DB_DATABASE' => 'homflo_e2e',
        E2eDatabaseGuard::RESET_ACKNOWLEDGEMENT => '1',
    ], $overrides);
}

test('accepts the exact E2E target environment without connecting', function () {
    E2eDatabaseGuard::validateEnvironment(e2eGuardEnvironment());

    expect(true)->toBeTrue();
});

test('rejects a missing reset acknowledgement before any database work', function () {
    $env = e2eGuardEnvironment();
    unset($env[E2eDatabaseGuard::RESET_ACKNOWLEDGEMENT]);

    expect(fn () => E2eDatabaseGuard::validateEnvironment($env))
        ->toThrow(RuntimeException::class, 'E2E_ALLOW_DATABASE_RESET must be exactly "1"');
});

test('rejects a wrong reset acknowledgement before any database work', function () {
    expect(fn () => E2eDatabaseGuard::validateEnvironment(
        e2eGuardEnvironment([E2eDatabaseGuard::RESET_ACKNOWLEDGEMENT => 'true'])
    ))->toThrow(RuntimeException::class, 'E2E_ALLOW_DATABASE_RESET must be exactly "1"');
});

test('rejects homflo_db before any database work', function () {
    expect(fn () => E2eDatabaseGuard::validateEnvironment(
        e2eGuardEnvironment(['DB_DATABASE' => 'homflo_db'])
    ))->toThrow(RuntimeException::class, 'DB_DATABASE must be exactly "homflo_e2e"');
});

test('rejects homflo_test before any database work', function () {
    expect(fn () => E2eDatabaseGuard::validateEnvironment(
        e2eGuardEnvironment(['DB_DATABASE' => 'homflo_test'])
    ))->toThrow(RuntimeException::class, 'DB_DATABASE must be exactly "homflo_e2e"');
});

test('rejects DB_URL before any database work', function () {
    expect(fn () => E2eDatabaseGuard::validateEnvironment(
        e2eGuardEnvironment(['DB_URL' => 'pgsql://homflo:secret@127.0.0.1/homflo_e2e'])
    ))->toThrow(RuntimeException::class, 'DB_URL must not be set');
});

test('rejects a live connection that resolves to a different database', function () {
    expect(fn () => E2eDatabaseGuard::assertLiveDatabase(
        ['database' => 'homflo_e2e'],
        fn () => 'homflo_db'
    ))->toThrow(RuntimeException::class, 'current_database() returned "homflo_db"');
});

test('accepts a live connection that resolves to homflo_e2e', function () {
    E2eDatabaseGuard::assertLiveDatabase(
        ['database' => 'homflo_e2e'],
        fn () => 'homflo_e2e'
    );

    expect(true)->toBeTrue();
});
