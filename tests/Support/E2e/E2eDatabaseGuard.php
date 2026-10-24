<?php

namespace Tests\Support\E2e;

use PDO;
use RuntimeException;

/**
 * Fail-closed guard for the authenticated E2E database.
 *
 * The guard is intentionally test-only. It validates the explicit process
 * environment before any destructive command or server process is allowed to
 * start, then verifies the database the active PostgreSQL connection really
 * reaches.
 */
final class E2eDatabaseGuard
{
    public const DATABASE = 'homflo_e2e';

    public const RESET_ACKNOWLEDGEMENT = 'E2E_ALLOW_DATABASE_RESET';

    /**
     * Validate the explicit environment that authorizes a reset or server.
     *
     * This method never connects to a database, so negative checks cannot
     * mutate anything even when they fail.
     *
     * @param  array<string, mixed>  $env
     */
    public static function validateEnvironment(array $env): void
    {
        self::requireExact($env, 'APP_ENV', 'e2e');
        self::requireExact($env, 'DB_CONNECTION', 'pgsql');
        self::requireExact($env, 'DB_DATABASE', self::DATABASE);
        self::requireExact($env, self::RESET_ACKNOWLEDGEMENT, '1');

        $dbUrl = self::value($env, 'DB_URL');

        if ($dbUrl !== null && $dbUrl !== '') {
            throw new RuntimeException('DB_URL must not be set for the authenticated E2E database.');
        }
    }

    /**
     * Read only the values the guard needs from the process environment.
     *
     * Laravel's immutable dotenv repository can populate $_ENV/$_SERVER
     * without making values visible to getenv(). Check all three sources so
     * the guard sees the same value the application will see.
     *
     * @return array<string, string>
     */
    public static function environment(): array
    {
        $keys = [
            'APP_ENV',
            'DB_CONNECTION',
            'DB_DATABASE',
            'DB_URL',
            self::RESET_ACKNOWLEDGEMENT,
        ];

        $values = [];

        foreach ($keys as $key) {
            $value = getenv($key);

            if ($value === false && array_key_exists($key, $_ENV)) {
                $value = $_ENV[$key];
            }

            if ($value === false && array_key_exists($key, $_SERVER)) {
                $value = $_SERVER[$key];
            }

            if ($value !== false) {
                $values[$key] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * Verify Laravel's resolved connection cannot bypass the guard through
     * DB_URL or a different driver/database.
     *
     * @param  array<string, mixed>  $connection
     */
    public static function validateConnection(array $connection): void
    {
        if (($connection['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('The E2E connection must use the pgsql driver.');
        }

        if (($connection['database'] ?? null) !== self::DATABASE) {
            throw new RuntimeException(sprintf(
                'The E2E connection database must be exactly "%s".',
                self::DATABASE
            ));
        }

        if (! empty($connection['url'])) {
            throw new RuntimeException('The E2E connection must not define a DB_URL.');
        }
    }

    /**
     * Verify the live connection resolves to exactly homflo_e2e.
     *
     * @param  array<string, mixed>  $connection
     * @param  (callable(array<string, mixed>): string)|null  $resolver
     */
    public static function assertLiveDatabase(array $connection, ?callable $resolver = null): void
    {
        $live = $resolver !== null
            ? (string) $resolver($connection)
            : self::liveDatabaseName($connection);

        if ($live !== self::DATABASE) {
            throw new RuntimeException(sprintf(
                'Refusing unsafe E2E target: current_database() returned "%s", expected "%s".',
                $live !== '' ? $live : '<empty>',
                self::DATABASE
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    public static function liveDatabaseName(array $connection): string
    {
        $pdo = self::connect($connection, (string) ($connection['database'] ?? ''));
        $row = $pdo->query('select current_database() as db')->fetch(PDO::FETCH_ASSOC);

        return (string) ($row['db'] ?? '');
    }

    /**
     * Create the dedicated database on the existing PostgreSQL service.
     *
     * The caller must have validated the environment first. This method also
     * re-checks the configured target name before opening the maintenance
     * connection, so it can never create a different database by accident.
     *
     * @param  array<string, mixed>  $connection
     */
    public static function ensureDatabaseExists(array $connection): void
    {
        if (($connection['database'] ?? null) !== self::DATABASE) {
            throw new RuntimeException('Refusing to create a database other than homflo_e2e.');
        }

        $maintenance = $connection;
        $maintenance['database'] = 'postgres';

        $pdo = self::connect($maintenance, 'postgres');
        $statement = $pdo->prepare('select 1 from pg_database where datname = :name');
        $statement->execute(['name' => self::DATABASE]);

        if ($statement->fetchColumn() !== false) {
            return;
        }

        // The identifier is a constant, never user input.
        $pdo->exec('CREATE DATABASE "'.self::DATABASE.'"');
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private static function connect(array $connection, string $database): PDO
    {
        $host = (string) ($connection['host'] ?? '127.0.0.1');
        $port = (string) ($connection['port'] ?? '5432');
        $username = (string) ($connection['username'] ?? '');
        $password = (string) ($connection['password'] ?? '');

        return new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database),
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    /**
     * @param  array<string, mixed>  $env
     */
    private static function requireExact(array $env, string $key, string $expected): void
    {
        $actual = self::value($env, $key);

        if ($actual !== $expected) {
            throw new RuntimeException(sprintf(
                '%s must be exactly "%s" (received %s).',
                $key,
                $expected,
                $actual === null ? '<missing>' : '"'.$actual.'"'
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $env
     */
    private static function value(array $env, string $key): ?string
    {
        if (! array_key_exists($key, $env)) {
            return null;
        }

        $value = $env[$key];

        if ($value === null || $value === false || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
