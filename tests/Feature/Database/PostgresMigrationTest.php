<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

describe('postgresql migration compatibility', function () {
    test('migrations run and roll back on PostgreSQL', function () {
        // phpunit forces DB_DATABASE=:memory: for the default connection;
        // point the pgsql connection at the dedicated test database first.
        config(['database.connections.pgsql.database' => env('DB_TEST_DATABASE', 'homflo_test')]);
        DB::purge('pgsql');

        try {
            DB::connection('pgsql')->getPdo();
        } catch (Throwable $exception) {
            $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini: '.$exception->getMessage());
        }

        Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);

        foreach ([
            'users',
            'households',
            'household_members',
            'categories',
            'lists',
            'list_items',
            'transactions',
            'budgets',
            'bills',
            'shopping_items',
            'assets',
            'maintenance',
            'vehicles',
            'vehicle_maintenance',
            'files',
            'properties',
        ] as $table) {
            expect(Schema::connection('pgsql')->hasTable($table))->toBeTrue();
        }

        expect(Schema::connection('pgsql')->hasColumn('assets', 'property_id'))->toBeTrue();

        $propertyForeignKey = collect(Schema::connection('pgsql')->getForeignKeys('assets'))
            ->first(fn (array $key) => $key['columns'] === ['property_id']);

        expect($propertyForeignKey)->not->toBeNull();
        expect($propertyForeignKey['foreign_table'])->toBe('properties');
        expect($propertyForeignKey['on_delete'])->toBe('set null');

        // Smoke: household-scoped insert, exact decimal round-trip, and the
        // two protective delete rules on the real grammar.
        $householdId = (string) Str::ulid();
        $categoryId = (string) Str::ulid();

        DB::connection('pgsql')->table('users')->insert([
            'id' => (string) Str::ulid(),
            'username' => 'pgsql-smoke',
            'name' => 'PG Smoke',
            'email' => 'pgsql-smoke@example.com',
            'password' => 'secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('pgsql')->table('households')->insert([
            'id' => $householdId,
            'name' => 'PG House',
            'created_by' => DB::connection('pgsql')->table('users')->where('email', 'pgsql-smoke@example.com')->value('id'),
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
        $billId = (string) Str::ulid();
        DB::connection('pgsql')->table('bills')->insert([
            'id' => $billId,
            'household_id' => $householdId,
            'title' => 'Internet',
            'amount' => '416250.00',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('pgsql')->table('transactions')->insert([
            'id' => (string) Str::ulid(),
            'household_id' => $householdId,
            'category_id' => $categoryId,
            'type' => 'expense',
            'amount' => '1234567.89',
            'occurred_on' => '2026-10-05',
            'source_type' => 'App\\Models\\Bill',
            'source_id' => $billId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(DB::connection('pgsql')->table('transactions')->where('household_id', $householdId)->count())->toBe(1);
        expect((string) DB::connection('pgsql')->table('transactions')->value('amount'))->toBe('1234567.89');
        expect((string) DB::connection('pgsql')->table('transactions')->value('source_id'))->toBe($billId);

        $propertyId = (string) Str::ulid();
        $assetId = (string) Str::ulid();

        DB::connection('pgsql')->table('properties')->insert([
            'id' => $propertyId,
            'household_id' => $householdId,
            'name' => 'PG Property',
            'type' => 'house',
            'address' => 'PG Address',
            'ownership_status' => 'owned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::connection('pgsql')->table('assets')->insert([
            'id' => $assetId,
            'household_id' => $householdId,
            'property_id' => $propertyId,
            'name' => 'PG Asset',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('pgsql')->table('properties')->where('id', $propertyId)->delete();

        expect(DB::connection('pgsql')->table('assets')->where('id', $assetId)->value('property_id'))->toBeNull();

        DB::connection('pgsql')->table('assets')->where('id', $assetId)->delete();

        $restrictHit = false;

        try {
            DB::connection('pgsql')->table('households')->where('id', $householdId)->delete();
        } catch (Throwable) {
            $restrictHit = true;
        }

        expect($restrictHit)->toBeTrue();

        DB::connection('pgsql')->table('transactions')->where('household_id', $householdId)->delete();
        DB::connection('pgsql')->table('households')->where('id', $householdId)->delete();

        expect(DB::connection('pgsql')->table('categories')->where('id', $categoryId)->count())->toBe(0);

        Artisan::call('migrate:rollback', ['--database' => 'pgsql', '--force' => true]);

        expect(Schema::connection('pgsql')->hasTable('files'))->toBeFalse();
        expect(Schema::connection('pgsql')->hasTable('transactions'))->toBeFalse();
        expect(Schema::connection('pgsql')->hasTable('properties'))->toBeFalse();
        expect(Schema::connection('pgsql')->hasTable('lists'))->toBeFalse();
        expect(Schema::connection('pgsql')->hasTable('list_items'))->toBeFalse();
        expect(Schema::connection('pgsql')->hasColumn('assets', 'property_id'))->toBeFalse();

        Artisan::call('migrate', ['--database' => 'pgsql', '--force' => true]);

        expect(Schema::connection('pgsql')->hasTable('files'))->toBeTrue();
        expect(Schema::connection('pgsql')->hasTable('properties'))->toBeTrue();
        expect(Schema::connection('pgsql')->hasColumn('assets', 'property_id'))->toBeTrue();
    });
});
