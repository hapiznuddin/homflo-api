<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Point the pgsql connection at the dedicated test database first: the
 * default connection is forced to :memory: by phpunit, but the test
 * database name lives in DB_TEST_DATABASE (homflo_test).
 *
 * @return bool true when PostgreSQL is reachable, false otherwise
 */
function pgBillConnect(): bool
{
    config(['database.connections.pgsql.database' => env('DB_TEST_DATABASE', 'homflo_test')]);
    DB::purge('pgsql');

    try {
        DB::connection('pgsql')->getPdo();
    } catch (Throwable) {
        return false;
    }

    return true;
}

function pgBillSeedFoundation(string $householdId, string $userId): void
{
    DB::connection('pgsql')->table('users')->insert([
        'id' => $userId,
        'username' => 'pg-b-'.$userId,
        'name' => 'PG Bill',
        'email' => 'pg-b-'.$userId.'@example.com',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::connection('pgsql')->table('households')->insert([
        'id' => $householdId,
        'name' => 'PG Bill House',
        'created_by' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('postgresql bill schema and constraints', function () {
    test('migrations add the recurrence schema and partial unique index on postgresql', function () {
        if (! pgBillConnect()) {
            $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
        }

        Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);

        expect(Schema::connection('pgsql')->hasTable('bill_recurrences'))->toBeTrue();

        $columns = Schema::connection('pgsql')->getColumnListing('bills');
        expect($columns)->toContain('recurrence_id', 'recurrence_period');

        $indexes = DB::connection('pgsql')->select(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'bills' AND indexname = 'bills_recurrence_id_recurrence_period_unique'"
        );

        expect($indexes)->toHaveCount(1);
        expect($indexes[0]->indexdef)->toContain('CREATE UNIQUE INDEX');
        expect($indexes[0]->indexdef)->toContain('WHERE');
        expect($indexes[0]->indexdef)->toContain('recurrence_id IS NOT NULL');

        $fk = DB::connection('pgsql')->select(
            "SELECT confdeltype FROM pg_constraint WHERE conname = 'bills_recurrence_id_foreign'"
        );
        expect($fk)->toHaveCount(1);
        expect($fk[0]->confdeltype)->toBe('n'); // nullOnDelete
    });

    test('duplicate periods are rejected while one-time null rows stay unlimited on postgresql', function () {
        if (! pgBillConnect()) {
            $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
        }

        Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);

        $userId = (string) Str::ulid();
        $householdId = (string) Str::ulid();
        $recurrenceId = (string) Str::ulid();

        pgBillSeedFoundation($householdId, $userId);

        DB::connection('pgsql')->table('bill_recurrences')->insert([
            'id' => $recurrenceId,
            'household_id' => $householdId,
            'title' => 'Sewa',
            'amount' => '150000.00',
            'frequency' => 'monthly',
            'due_day' => 15,
            'is_active' => true,
            'materialized_until' => '2027-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $periodRow = [
            'id' => (string) Str::ulid(),
            'household_id' => $householdId,
            'title' => 'Sewa Jan',
            'amount' => '150000.00',
            'due_date' => '2027-01-15',
            'is_active' => true,
            'recurrence_id' => $recurrenceId,
            'recurrence_period' => '2027-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::connection('pgsql')->table('bills')->insert($periodRow);

        $duplicateRejected = false;

        try {
            DB::connection('pgsql')->table('bills')->insert(array_merge($periodRow, [
                'id' => (string) Str::ulid(),
            ]));
        } catch (Throwable) {
            $duplicateRejected = true;
        }

        expect($duplicateRejected)->toBeTrue();
        expect(DB::connection('pgsql')->table('bills')->where('recurrence_id', $recurrenceId)->count())->toBe(1);

        // NULLs are distinct in PostgreSQL: one-time bills (NULL, NULL)
        // must stay unlimited by the partial index.
        foreach (['2030-01-01', '2030-01-02', '2030-01-03'] as $dueDate) {
            DB::connection('pgsql')->table('bills')->insert([
                'id' => (string) Str::ulid(),
                'household_id' => $householdId,
                'title' => 'Satu Kali',
                'amount' => '1000.00',
                'due_date' => $dueDate,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        expect(DB::connection('pgsql')->table('bills')->whereNull('recurrence_id')->count())->toBe(3);
    });

    test('null-safe due date sort behaves identically on postgresql and sqlite', function () {
        if (! pgBillConnect()) {
            $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
        }

        Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);

        $userId = (string) Str::ulid();
        $householdId = (string) Str::ulid();
        pgBillSeedFoundation($householdId, $userId);

        // Identical fixture on both connections; ids double as order
        // probes so the two drivers' sequences can be compared directly.
        $fixture = [
            ['sort-e', null, '2026-01-01 00:00:00'],
            ['sort-c', '2026-03-01', '2026-01-02 00:00:00'],
            ['sort-d', '2026-01-15', '2026-01-03 00:00:00'],
            ['sort-b', '2026-01-15', '2026-01-01 00:00:00'],
            ['sort-a', '2025-12-31', '2026-01-04 00:00:00'],
        ];

        // The sqlite side (default connection, migrated by the test case)
        // gets its own foundation rows with matching structure.
        DB::table('users')->insert([
            'id' => 'sq-user',
            'username' => 'sq-b-user',
            'name' => 'SQ Bill',
            'email' => 'sq-b-user@example.com',
            'password' => 'secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('households')->insert([
            'id' => 'sq-household',
            'name' => 'SQ Bill House',
            'created_by' => 'sq-user',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($fixture as [$id, $dueDate, $createdAt]) {
            $row = [
                'id' => $id,
                'title' => 'Urut',
                'amount' => '1000.00',
                'is_active' => true,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            DB::connection('pgsql')->table('bills')->insert($row + [
                'household_id' => $householdId,
                'due_date' => $dueDate,
            ]);
            DB::table('bills')->insert($row + [
                'household_id' => 'sq-household',
                'due_date' => $dueDate,
            ]);
        }

        $sortSql = 'SELECT id FROM bills WHERE household_id = ? AND is_active = true '
            .'ORDER BY (due_date IS NULL), due_date ASC, created_at ASC';

        // bills.id is CHAR(26) on PostgreSQL (Laravel compiles ulid() to a
        // fixed-width char) while SQLite stores TEXT: PG pads the short
        // probe ids with trailing spaces on retrieval, SQLite does not.
        // Production ULIDs are always exactly 26 characters, so the
        // padding only ever affects these fixture probes — normalize it
        // here on both result sets, never in the application.
        $pgOrder = array_map(
            'trim',
            array_column(DB::connection('pgsql')->select($sortSql, [$householdId]), 'id'),
        );
        $sqliteOrder = array_map(
            'trim',
            array_column(DB::select($sortSql, ['sq-household']), 'id'),
        );

        expect($pgOrder)->toBe(['sort-a', 'sort-b', 'sort-d', 'sort-c', 'sort-e']);
        expect($sqliteOrder)->toBe($pgOrder);
    });
});
