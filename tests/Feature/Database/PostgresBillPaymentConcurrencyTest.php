<?php

use App\Exceptions\BillAlreadyPaidException;
use App\Exceptions\InsufficientFundsException;
use App\Models\Bill;
use App\Models\Household;
use App\Services\Finance\BillService;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
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
function pgBillPayConnect(): bool
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

/**
 * Raw fixture rows for a payable bill: funded wallet + expense category
 * + bill, all in one household.
 *
 * @return array{household_id: string, bill_id: string, wallet_id: string, category_id: string}
 */
function pgBillPaySeed(string $openingBalance): array
{
    $userId = (string) Str::ulid();
    $householdId = (string) Str::ulid();
    $categoryId = (string) Str::ulid();
    $billId = (string) Str::ulid();
    $walletId = (string) Str::ulid();

    DB::connection('pgsql')->table('users')->insert([
        'id' => $userId,
        'username' => 'pg-p-'.$userId,
        'name' => 'PG Bill Payment',
        'email' => 'pg-p-'.$userId.'@example.com',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::connection('pgsql')->table('households')->insert([
        'id' => $householdId,
        'name' => 'PG Payment House',
        'created_by' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::connection('pgsql')->table('categories')->insert([
        'id' => $categoryId,
        'household_id' => $householdId,
        'name' => 'Tagihan PG',
        'icon' => null,
        'type' => 'expense',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::connection('pgsql')->table('bills')->insert([
        'id' => $billId,
        'household_id' => $householdId,
        'category_id' => $categoryId,
        'title' => 'Internet PG',
        'amount' => '150000.00',
        'due_date' => '2026-12-05',
        'is_active' => true,
        'notes' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::connection('pgsql')->table('wallets')->insert([
        'id' => $walletId,
        'household_id' => $householdId,
        'name' => 'Dompet PG',
        'type' => 'cash',
        'opening_balance' => $openingBalance,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'household_id' => $householdId,
        'bill_id' => $billId,
        'wallet_id' => $walletId,
        'category_id' => $categoryId,
    ];
}

/**
 * Open the second real connection (writer A) used to interleave with
 * the service call on the default connection (writer B).
 */
function pgBillPayFirstConnection(): Connection
{
    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
    DB::purge('pgsql_second');

    return DB::connection('pgsql_second');
}

function pgBillPayReleaseFirstConnection(): void
{
    DB::connection('pgsql_second')->disconnect();
    config()->offsetUnset('database.connections.pgsql_second');
    DB::purge('pgsql_second');
}

describe('postgresql bill payment concurrency', function () {
    test('the bill row lock blocks a concurrent payment until the first writer commits, then the loser gets BILL_ALREADY_PAID', function () {
        if (! pgBillPayConnect()) {
            $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
        }

        Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);

        $ids = pgBillPaySeed('1000000.00');

        // Writer B = the real service, running on the default pgsql
        // connection (sqlite stays the phpunit default elsewhere).
        // The default switch is restored in finally: RefreshDatabase
        // evaluates connectionsToTransact() during teardown, so a leaked
        // pgsql default would skip the sqlite :memory: rollback, reset
        // RefreshDatabaseState::$migrated, and poison the next tests.
        $previousDefault = config('database.default');
        config(['database.default' => 'pgsql']);

        try {
            $household = Household::query()->findOrFail($ids['household_id']);
            $bill = Bill::query()->findOrFail($ids['bill_id']);

            // Writer A: separate real connection — holds the bill row lock
            // and stages an uncommitted payment of the same bill.
            $first = pgBillPayFirstConnection();
            $first->beginTransaction();

            $lockedByA = $first
                ->table('bills')
                ->where('id', $ids['bill_id'])
                ->lockForUpdate()
                ->first();
            expect($lockedByA)->not->toBeNull();

            $first->table('transactions')->insert([
                'id' => (string) Str::ulid(),
                'household_id' => $ids['household_id'],
                'category_id' => $ids['category_id'],
                'wallet_id' => $ids['wallet_id'],
                'type' => 'expense',
                'amount' => '150000.00',
                'occurred_on' => '2026-10-19',
                'source_type' => Bill::class,
                'source_id' => $ids['bill_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Writer B with a short lock timeout: pay() must genuinely block
            // on A's bill row lock — SQLite can never demonstrate this.
            DB::connection('pgsql')->statement("SET lock_timeout = '300ms'");

            $blockedOnBillLock = false;

            try {
                app(BillService::class)->pay($household, $bill, ['wallet_id' => $ids['wallet_id']]);
            } catch (QueryException $exception) {
                if (! str_contains($exception->getMessage(), 'lock timeout')) {
                    throw $exception;
                }

                $blockedOnBillLock = true;
            }

            expect($blockedOnBillLock)->toBeTrue();

            // B wrote nothing while blocked: A's staged row is invisible to B.
            expect(
                DB::connection('pgsql')
                    ->table('transactions')
                    ->where('source_type', Bill::class)
                    ->where('source_id', $ids['bill_id'])
                    ->count()
            )->toBe(0);

            // A commits; B retries under the lock and must lose against the
            // committed payment — never double-pay.
            $first->commit();

            DB::connection('pgsql')->statement('SET lock_timeout = 0');

            expect(fn () => app(BillService::class)->pay($household, $bill, ['wallet_id' => $ids['wallet_id']]))
                ->toThrow(BillAlreadyPaidException::class);

            $payments = DB::connection('pgsql')
                ->table('transactions')
                ->where('source_type', Bill::class)
                ->where('source_id', $ids['bill_id'])
                ->get();

            expect($payments)->toHaveCount(1);
            expect((string) $payments->first()->amount)->toBe('150000.00');

            $expenseTotal = DB::connection('pgsql')
                ->table('transactions')
                ->where('wallet_id', $ids['wallet_id'])
                ->where('type', 'expense')
                ->sum('amount');
            expect(number_format((float) $expenseTotal, 2, '.', ''))->toBe('150000.00');

            pgBillPayReleaseFirstConnection();
        } finally {
            config(['database.default' => $previousDefault]);
        }
    });

    test('a payment serialized behind a plain row lock still succeeds exactly once', function () {
        if (! pgBillPayConnect()) {
            $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
        }

        Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);

        $ids = pgBillPaySeed('1000000.00');

        // Restored in finally — see the comment in the first test: a
        // leaked pgsql default breaks RefreshDatabase's sqlite teardown.
        $previousDefault = config('database.default');
        config(['database.default' => 'pgsql']);

        try {
            $household = Household::query()->findOrFail($ids['household_id']);
            $bill = Bill::query()->findOrFail($ids['bill_id']);

            // A locks the bill row only — no staged payment this time.
            $first = pgBillPayFirstConnection();
            $first->beginTransaction();

            $lockedByA = $first
                ->table('bills')
                ->where('id', $ids['bill_id'])
                ->lockForUpdate()
                ->first();
            expect($lockedByA)->not->toBeNull();

            DB::connection('pgsql')->statement("SET lock_timeout = '300ms'");

            $blockedOnBillLock = false;

            try {
                app(BillService::class)->pay($household, $bill, ['wallet_id' => $ids['wallet_id']]);
            } catch (QueryException $exception) {
                if (! str_contains($exception->getMessage(), 'lock timeout')) {
                    throw $exception;
                }

                $blockedOnBillLock = true;
            }

            expect($blockedOnBillLock)->toBeTrue();

            // After A releases, the retried payment commits exactly once —
            // serialization never erases a legitimate write.
            $first->commit();

            DB::connection('pgsql')->statement('SET lock_timeout = 0');

            $payment = app(BillService::class)->pay($household, $bill, ['wallet_id' => $ids['wallet_id']]);
            expect($payment->id)->not->toBeNull();

            $payments = DB::connection('pgsql')
                ->table('transactions')
                ->where('source_type', Bill::class)
                ->where('source_id', $ids['bill_id'])
                ->get();
            expect($payments)->toHaveCount(1);

            $expenseTotal = DB::connection('pgsql')
                ->table('transactions')
                ->where('wallet_id', $ids['wallet_id'])
                ->where('type', 'expense')
                ->sum('amount');
            expect(number_format((float) $expenseTotal, 2, '.', ''))->toBe('150000.00');

            // Derived balance: 1,000,000.00 − 150,000.00.
            $opening = DB::connection('pgsql')
                ->table('wallets')
                ->where('id', $ids['wallet_id'])
                ->value('opening_balance');
            expect(number_format((float) $opening - (float) $expenseTotal, 2, '.', ''))->toBe('850000.00');

            pgBillPayReleaseFirstConnection();
        } finally {
            config(['database.default' => $previousDefault]);
        }
    });

    test('an insufficient-funds payment leaves no row behind on postgresql', function () {
        if (! pgBillPayConnect()) {
            $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
        }

        Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);

        $ids = pgBillPaySeed('1000.00');

        // Restored in finally — see the comment in the first test: a
        // leaked pgsql default breaks RefreshDatabase's sqlite teardown.
        $previousDefault = config('database.default');
        config(['database.default' => 'pgsql']);

        try {
            $household = Household::query()->findOrFail($ids['household_id']);
            $bill = Bill::query()->findOrFail($ids['bill_id']);

            expect(fn () => app(BillService::class)->pay($household, $bill, ['wallet_id' => $ids['wallet_id']]))
                ->toThrow(InsufficientFundsException::class);

            // Full rollback on a real transactional engine: no payment row,
            // the derived balance stays exactly the opening balance.
            expect(
                DB::connection('pgsql')
                    ->table('transactions')
                    ->where('source_type', Bill::class)
                    ->where('source_id', $ids['bill_id'])
                    ->count()
            )->toBe(0);

            $expenseTotal = DB::connection('pgsql')
                ->table('transactions')
                ->where('wallet_id', $ids['wallet_id'])
                ->where('type', 'expense')
                ->sum('amount');
            expect(number_format((float) $expenseTotal, 2, '.', ''))->toBe('0.00');
        } finally {
            config(['database.default' => $previousDefault]);
        }
    });
});
