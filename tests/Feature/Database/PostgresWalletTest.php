<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function pgWalletHousehold(): array
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
    $walletId = (string) Str::ulid();

    DB::connection('pgsql')->table('users')->insert([
        'id' => $userId,
        'username' => 'pg-w-'.$userId,
        'name' => 'PG Wallet',
        'email' => 'pg-w-'.$userId.'@example.com',
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
    DB::connection('pgsql')->table('wallets')->insert([
        'id' => $walletId,
        'household_id' => $householdId,
        'name' => 'Kas',
        'type' => 'cash',
        'opening_balance' => '100000.00',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$householdId, $walletId];
}

describe('postgresql wallet constraints', function () {
    test('wallet name stays unique per household including inactive wallets', function () {
        try {
            [$householdId] = pgWalletHousehold();
        } catch (RuntimeException $exception) {
            $this->markTestSkipped($exception->getMessage());
        }

        DB::connection('pgsql')->table('wallets')->insert([
            'id' => (string) Str::ulid(),
            'household_id' => $householdId,
            'name' => 'Kas',
            'type' => 'bank',
            'opening_balance' => '0.00',
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(false)->toBeTrue('duplicate wallet name was accepted');
    })->throws(QueryException::class);

    test('wallet references from the ledger are restrict-protected', function () {
        try {
            [$householdId, $walletId] = pgWalletHousehold();
        } catch (RuntimeException $exception) {
            $this->markTestSkipped($exception->getMessage());
        }

        DB::connection('pgsql')->table('transactions')->insert([
            'id' => (string) Str::ulid(),
            'household_id' => $householdId,
            'wallet_id' => $walletId,
            'type' => 'income',
            'amount' => '50000.00',
            'occurred_on' => '2026-10-05',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('pgsql')->table('wallets')->where('id', $walletId)->delete();

        expect(false)->toBeTrue('ledger-linked wallet was deleted');
    })->throws(QueryException::class);

    test('row-level locks and exact decimal sums run on postgresql', function () {
        try {
            [$householdId, $walletId] = pgWalletHousehold();
        } catch (RuntimeException $exception) {
            $this->markTestSkipped($exception->getMessage());
        }

        DB::connection('pgsql')->transaction(function () use ($householdId, $walletId) {
            $locked = DB::connection('pgsql')->table('wallets')
                ->where('household_id', $householdId)
                ->whereIn('id', [$walletId])
                ->lockForUpdate()
                ->get();

            expect($locked)->toHaveCount(1);

            $sum = DB::connection('pgsql')->table('transactions')
                ->where('household_id', $householdId)
                ->where('wallet_id', $walletId)
                ->sum('amount');

            expect((string) $sum)->toBe('0');
        });
    });

    test('wallet migration columns exist with the expected shapes', function () {
        try {
            pgWalletHousehold();
        } catch (RuntimeException $exception) {
            $this->markTestSkipped($exception->getMessage());
        }

        expect(Schema::connection('pgsql')->hasColumns('wallets', [
            'id', 'household_id', 'name', 'type', 'opening_balance', 'is_active',
        ]))->toBeTrue();
        expect(Schema::connection('pgsql')->hasColumns('transactions', [
            'wallet_id', 'destination_wallet_id',
        ]))->toBeTrue();
        expect(Schema::connection('pgsql')->hasColumns('categories', ['type']))->toBeTrue();
        expect(Schema::connection('pgsql')->hasTable('wallet_adjustments'))->toBeTrue();
    });
});
