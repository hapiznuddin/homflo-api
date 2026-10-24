<?php

use Illuminate\Contracts\Console\Kernel;
use Tests\Support\E2e\E2eDatabaseGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';

/**
 * Start the API E2E process only after the fail-closed target checks pass.
 *
 * This wrapper is test-only. It uses PHP's built-in server with Laravel's own
 * router script so the process environment is inherited directly instead of
 * being filtered by `artisan serve`.
 */
function e2eServeFail(string $message, int $exitCode = 1): never
{
    fwrite(STDERR, '[e2e-serve] '.$message.PHP_EOL);
    exit($exitCode);
}

try {
    E2eDatabaseGuard::validateEnvironment(E2eDatabaseGuard::environment());
} catch (Throwable $exception) {
    e2eServeFail($exception->getMessage(), 2);
}

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
} catch (Throwable $exception) {
    e2eServeFail('Laravel bootstrap failed: '.$exception->getMessage(), 2);
}

if (! is_string(config('app.key')) || config('app.key') === '') {
    e2eServeFail('APP_KEY is not configured. Set it in the ignored API .env file.', 2);
}

$connection = config('database.connections.pgsql');

if (! is_array($connection)) {
    e2eServeFail('The pgsql connection is not configured.', 2);
}

try {
    E2eDatabaseGuard::validateConnection($connection);
    E2eDatabaseGuard::assertLiveDatabase($connection);
} catch (Throwable $exception) {
    e2eServeFail('Refusing to start API: '.$exception->getMessage(), 3);
}

$host = getenv('E2E_API_HOST') ?: '127.0.0.1';
$port = getenv('E2E_API_PORT') ?: '8001';

if (! preg_match('/^\d{2,5}$/', $port)) {
    e2eServeFail('E2E_API_PORT must be a numeric port.', 2);
}

$basePath = dirname(__DIR__, 2);
$router = $basePath.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';

if (! is_file($router)) {
    e2eServeFail('Laravel built-in server router is missing.', 2);
}

if (! function_exists('pcntl_exec')) {
    e2eServeFail('pcntl_exec is required for the E2E API process.', 2);
}

if (! chdir($basePath.'/public')) {
    e2eServeFail('Unable to enter the public directory.', 2);
}

$command = PHP_BINARY;
$arguments = ['-S', $host.':'.$port, $router];

pcntl_exec($command, $arguments);

e2eServeFail('Unable to exec the PHP built-in server.', 3);
