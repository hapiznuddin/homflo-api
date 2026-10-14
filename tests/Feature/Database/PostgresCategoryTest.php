<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function pgCategoriesConnection(): void
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
}

function pgSeedCategoryHousehold(): array
{
    $userId = (string) Str::ulid();
    $householdId = (string) Str::ulid();

    DB::connection('pgsql')->table('users')->insert([
        'id' => $userId,
        'username' => 'pg-cat-'.$userId,
        'name' => 'PG Category',
        'email' => 'pg-cat-'.$userId.'@example.com',
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

    return [$householdId];
}

describe('postgresql category constraints', function () {
    test('category name uniqueness is case-sensitive', function () {
        try {
            pgCategoriesConnection();
        } catch (RuntimeException $exception) {
            $this->markTestSkipped($exception->getMessage());
        }

        [$householdId] = pgSeedCategoryHousehold();

        DB::connection('pgsql')->table('categories')->insert([
            'id' => (string) Str::ulid(),
            'household_id' => $householdId,
            'name' => 'Dapur',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('pgsql')->table('categories')->insert([
            'id' => (string) Str::ulid(),
            'household_id' => $householdId,
            'name' => 'DAPUR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(DB::connection('pgsql')->table('categories')->where('household_id', $householdId)->count())->toBe(2);

        $duplicateRejected = false;

        try {
            DB::connection('pgsql')->table('categories')->insert([
                'id' => (string) Str::ulid(),
                'household_id' => $householdId,
                'name' => 'Dapur',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            $duplicateRejected = true;
        }

        expect($duplicateRejected)->toBeTrue();
    });

    test('budget usage blocks category deletion while transactions detach', function () {
        try {
            pgCategoriesConnection();
        } catch (RuntimeException $exception) {
            $this->markTestSkipped($exception->getMessage());
        }

        [$householdId] = pgSeedCategoryHousehold();
        $categoryId = (string) Str::ulid();

        DB::connection('pgsql')->table('categories')->insert([
            'id' => $categoryId,
            'household_id' => $householdId,
            'name' => 'Dapur',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
        $transactionId = (string) Str::ulid();
        DB::connection('pgsql')->table('transactions')->insert([
            'id' => $transactionId,
            'household_id' => $householdId,
            'category_id' => $categoryId,
            'type' => 'expense',
            'amount' => '100000.00',
            'occurred_on' => '2026-10-05',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $restrictHit = false;

        try {
            DB::connection('pgsql')->table('categories')->where('id', $categoryId)->delete();
        } catch (Throwable) {
            $restrictHit = true;
        }

        expect($restrictHit)->toBeTrue();

        DB::connection('pgsql')->table('budgets')->where('category_id', $categoryId)->delete();
        DB::connection('pgsql')->table('categories')->where('id', $categoryId)->delete();

        expect(DB::connection('pgsql')->table('transactions')->where('id', $transactionId)->value('category_id'))->toBeNull();
    });
});
