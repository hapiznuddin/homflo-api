<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Point the pgsql connection at the dedicated test database first: the
 * default connection is forced to :memory: by phpunit, but the test
 * database name lives in DB_TEST_DATABASE (homflo_test).
 *
 * @return bool true when PostgreSQL is reachable, false otherwise
 */
function pgBillConcurrencyConnect(): bool
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

describe('postgresql materialization concurrency', function () {
    test('the recurrence row lock blocks a second writer until the first commits', function () {
        if (! pgBillConcurrencyConnect()) {
            $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
        }

        Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);

        $userId = (string) Str::ulid();
        $householdId = (string) Str::ulid();
        $recurrenceId = (string) Str::ulid();

        DB::connection('pgsql')->table('users')->insert([
            'id' => $userId,
            'username' => 'pg-c-'.$userId,
            'name' => 'PG Concurrency',
            'email' => 'pg-c-'.$userId.'@example.com',
            'password' => 'secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('pgsql')->table('households')->insert([
            'id' => $householdId,
            'name' => 'PG Concurrency House',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

        // Writer A takes the schedule row exactly like the materialization
        // path does (SELECT ... FOR UPDATE) and holds the transaction.
        DB::connection('pgsql')->beginTransaction();
        $lockedByA = DB::connection('pgsql')
            ->table('bill_recurrences')
            ->where('id', $recurrenceId)
            ->lockForUpdate()
            ->first();
        expect($lockedByA)->not->toBeNull();

        // Writer B opens a separate real connection; with lock_timeout set,
        // its FOR UPDATE must fail instead of hanging — proof that A's row
        // lock is genuinely blocking the concurrent writer on PostgreSQL.
        config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
        DB::purge('pgsql_second');
        $second = DB::connection('pgsql_second');
        $second->statement("SET lock_timeout = '300ms'");

        $blockedByLock = false;

        try {
            $second
                ->table('bill_recurrences')
                ->where('id', $recurrenceId)
                ->lockForUpdate()
                ->first();
        } catch (Throwable) {
            $blockedByLock = true;
        }

        expect($blockedByLock)->toBeTrue();

        // Once A commits, B acquires the lock and re-reads the cursor —
        // the same recheck-after-lock the materialization engine relies on.
        DB::connection('pgsql')->commit();

        $second->statement('SET lock_timeout = 0');
        $rowForB = $second
            ->table('bill_recurrences')
            ->where('id', $recurrenceId)
            ->lockForUpdate()
            ->first();

        expect($rowForB)->not->toBeNull();
        expect($rowForB->materialized_until)->toBe('2027-01-01');

        DB::connection('pgsql_second')->disconnect();
        config()->offsetUnset('database.connections.pgsql_second');
        DB::purge('pgsql_second');
    });
});
