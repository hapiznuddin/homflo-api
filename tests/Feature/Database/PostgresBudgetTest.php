<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function pgBudgetHousehold(): array
{
    // phpunit forces DB_DATABASE=:memory: for the default connection;
    // point the pgsql connection at the dedicated test database first.
    config(['database.connections.pgsql.database' => env('DB_TEST_DATABASE', 'homflo_test')]);
    DB::purge('pgsql');

    try {
        DB::connection('pgsql')->getPdo();
    } catch (Throwable $exception) {
        throw new RuntimeException('PostgreSQL tidak tersedia: '.$exception->getMessage());
    }

    $userId = (string) Str::ulid();
    $householdId = (string) Str::ulid();
    $categoryId = (string) Str::ulid();

    DB::connection('pgsql')->table('users')->insert([
        'id' => $userId,
        'username' => 'pg-bg-'.$userId,
        'name' => 'PG Budget',
        'email' => 'pg-bg-'.$userId.'@example.com',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::connection('pgsql')->table('households')->insert([
        'id' => $householdId,
        'name' => 'PG House',
        'created_by' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::connection('pgsql')->table('categories')->insert([
        'id' => $categoryId,
        'household_id' => $householdId,
        'name' => 'Dapur',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$householdId, $categoryId];
}

describe('postgresql budget constraints', function () {
    test('unique target per household, category, and period holds', function () {
        try {
            [$householdId, $categoryId] = pgBudgetHousehold();
        } catch (RuntimeException $exception) {
            $this->markTestSkipped($exception->getMessage());
        }

        DB::connection('pgsql')->table('budgets')->insert([
            'id' => (string) Str::ulid(),
            'household_id' => $householdId,
            'category_id' => $categoryId,
            'year' => 2026,
            'month' => 10,
            'amount' => '500000.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $duplicateRejected = false;

        try {
            DB::connection('pgsql')->table('budgets')->insert([
                'id' => (string) Str::ulid(),
                'household_id' => $householdId,
                'category_id' => $categoryId,
                'year' => 2026,
                'month' => 10,
                'amount' => '600000.00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            $duplicateRejected = true;
        }

        expect($duplicateRejected)->toBeTrue();
    });

    test('decimal precision round-trips exactly', function () {
        try {
            [$householdId, $categoryId] = pgBudgetHousehold();
        } catch (RuntimeException $exception) {
            $this->markTestSkipped($exception->getMessage());
        }

        $budgetId = (string) Str::ulid();

        DB::connection('pgsql')->table('budgets')->insert([
            'id' => $budgetId,
            'household_id' => $householdId,
            'category_id' => $categoryId,
            'year' => 2026,
            'month' => 10,
            'amount' => '1234567.89',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect((string) DB::connection('pgsql')->table('budgets')->where('id', $budgetId)->value('amount'))
            ->toBe('1234567.89');
    });

    test('grouped expense aggregation is exact on real grammar', function () {
        try {
            [$householdId, $categoryId] = pgBudgetHousehold();
        } catch (RuntimeException $exception) {
            $this->markTestSkipped($exception->getMessage());
        }

        foreach (['100000.10', '200000.20'] as $amount) {
            DB::connection('pgsql')->table('transactions')->insert([
                'id' => (string) Str::ulid(),
                'household_id' => $householdId,
                'category_id' => $categoryId,
                'type' => 'expense',
                'amount' => $amount,
                'occurred_on' => '2026-10-05',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::connection('pgsql')->table('transactions')->insert([
            'id' => (string) Str::ulid(),
            'household_id' => $householdId,
            'category_id' => $categoryId,
            'type' => 'income',
            'amount' => '9000000.00',
            'occurred_on' => '2026-10-05',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $total = DB::connection('pgsql')->table('transactions')
            ->where('household_id', $householdId)
            ->where('type', 'expense')
            ->whereDate('occurred_on', '>=', '2026-10-01')
            ->whereDate('occurred_on', '<=', '2026-10-31')
            ->sum('amount');

        expect((string) $total)->toBe('300000.30');
    });
});
