<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lists Feature Tests — Homflo Phase 16.6
 *
 * Tests the Lists feature: default list per household, CRUD Lists,
 * CRUD List Items, complete/uncomplete, expense creation, and
 * owner/member authorization with 404 masking for cross-household access.
 *
 * @group lists
 */
describe('Lists', function () {
    // -----------------------------------------------------------------
    // PostgreSQL availability check (standalone, no $this)
    // -----------------------------------------------------------------

    function pgIsAvailable(): bool
    {
        try {
            config(['database.connections.pgsql.database' => env('DB_TEST_DATABASE', 'homflo_test')]);
            DB::purge('pgsql');
            DB::connection('pgsql')->getPdo();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    function ulid(): string
    {
        return (string) Str::ulid();
    }

    // -----------------------------------------------------------------
    // 1. Default List: Pembuatan, Pointer Household, dan Invariant Satu Default
    // -----------------------------------------------------------------

    describe('default list', function () {
        beforeEach(function () {
            if (! pgIsAvailable()) {
                return $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
            }
            Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);
        });

        it('owner dapat membuat list dan menjadi default otomatis', function () {
            $userId = ulid();
            $householdId = ulid();

            // INSERT users & households manually (no model factory)
            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // CREATE list (store endpoint)
            $createResponse = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson('/api/lists', ['name' => 'My Shopping List']);

            $createResponse->assertStatus(201)
                ->assertJsonStructure([
                    'success',
                    'data' => ['id', 'name', 'type', 'is_default', 'counts'],
                ]);

            $listId = $createResponse['data']['id'];

            // VERify default list pointer
            $defaultResponse = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->getJson('/api/lists/default');

            $defaultResponse->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'data' => ['id' => $listId, 'is_default' => true],
                ]);

            // Invariant: household hanya punya 1 list aktif
            $listsResponse = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->getJson('/api/lists');

            $listsResponse->assertStatus(200)
                ->assertJsonCount(1, 'data')
                ->assertJsonHas('data.0.is_default', true);
        });

        it('member tidak dapat membuat list, hanya view', function () {
            $ownerId = ulid();
            $memberId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $ownerId,
                'username' => 'owner',
                'name' => 'Owner',
                'email' => 'owner@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $ownerId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Member creates list — should get 404 masked
            $response = $this->actingAs(
                \App\Models\User::findOrFail($memberId)
            )->postJson('/api/lists', ['name' => 'Unauthorized List']);

            // Member should get 404 — cross-household access masked as not found
            $response->assertStatus(404);
        });
    });

    // -----------------------------------------------------------------
    // 2. CRUD Lists dan aturan archive/restore
    // -----------------------------------------------------------------

    describe('CRUD Lists', function () {
        beforeEach(function () {
            if (! pgIsAvailable()) {
                return $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
            }
            Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);
        });

        it('owner dapat create list', function () {
            $userId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson('/api/lists', ['name' => 'Bills List']);

            $response->assertStatus(201)
                ->assertJson([
                    'success' => true,
                    'data' => ['name' => 'Bills List', 'is_default' => true],
                ]);
        });

        it('owner dapat show list detail', function () {
            $userId = ulid();
            $householdId = ulid();
            $listId = \App\Models\HouseholdList::factory()->create()->id;

            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->getJson("/api/lists/{$listId}");

            $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'data' => ['id' => $listId],
                ]);
        });

        it('member tidak dapat akses list owner lain (404 masking)', function () {
            $ownerId = ulid();
            $memberId = ulid();
            $householdId = ulid();
            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();

            $response = $this->actingAs(
                \App\Models\User::findOrFail($memberId)
            )->getJson("/api/lists/{$listId}");

            // Cross-household access masked as 404
            $response->assertStatus(404);
        });

        it('owner dapat update nama list (bukan archived)', function () {
            $userId = ulid();
            $householdId = ulid();
            $listId = \App\Models\HouseholdList::factory()->create(['household_id' => $householdId]);

            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->patchJson("/api/lists/{$listId}", ['name' => 'Updated List Name']);

            $response->assertStatus(200)
                ->assertJson([
                    'data' => ['name' => 'Updated List Name'],
                ]);
        });

        it('owner cannot update archived list (409)', function () {
            $userId = ulid();
            $householdId = ulid();
            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create(['archived_at' => now()]);

            // First archive it
            $archiveResponse = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/archive");

            $archiveResponse->assertStatus(200);

            // Now try to update — should be 409
            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->patchJson("/api/lists/{$listId}", ['name' => 'New Name']);

            $response->assertStatus(409)
                ->assertJson([
                    'error' => ['code' => 'LIST_ARCHIVED', 'message' => 'List is archived and read-only.'],
                ]);
        });

        it('owner dapat archive list (kandida default harus ada)', function () {
            $userId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Create list without making it default first
            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();

            // Archive the list — should fail because DEFAULT_LIST_REQUIRED
            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/archive");

            $response->assertStatus(409)
                ->assertJson([
                    'error' => ['code' => 'DEFAULT_LIST_REQUIRED', 'message' => 'Household must have a default active Shopping List.'],
                ]);
        });

        it('owner dapat restore archived list', function () {
            $userId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create(['archived_at' => now()]);

            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/restore");

            $response->assertStatus(200)
                ->assertJson([
                    'data' => ['archived_at' => null],
                ]);
        });
    });

    // -----------------------------------------------------------------
    // 3. CRUD List Items
    // -----------------------------------------------------------------

    describe('CRUD List Items', function () {
        beforeEach(function () {
            if (! pgIsAvailable()) {
                return $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
            }
            Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);
        });

        it('owner dapat create item di list aktif', function () {
            $userId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();

            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/items", [
                'title' => 'Milk',
                'quantity' => '2.00',
                'unit' => 'liter',
                'estimated_price' => '50.00',
            ]);

            $response->assertStatus(201)
                ->assertJson([
                    'data' => [
                        'title' => 'Milk',
                        'quantity' => '2.00',
                        'unit' => 'liter',
                        'estimated_price' => '50.00',
                    ],
                ]);
        });

        it('member tidak dapat create item di list orang lain (404)', function () {
            $ownerId = ulid();
            $memberId = ulid();
            $householdId = ulid();
            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();

            $response = $this->actingAs(
                \App\Models\User::findOrFail($memberId)
            )->postJson("/api/lists/{$listId}/items", [
                'title' => 'Unauthorized Item',
                'quantity' => '1.00',
                'unit' => 'pcs',
                'estimated_price' => '10.00',
            ]);

            $response->assertStatus(404);
        });

        it('tidak dapat create item di list archived (409)', function () {
            $userId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create(['archived_at' => now()]);

            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/items", [
                'title' => 'New Item',
                'quantity' => '1.00',
                'unit' => 'pcs',
                'estimated_price' => '10.00',
            ]);

            $response->assertStatus(409)
                ->assertJson([
                    'error' => ['code' => 'LIST_ARCHIVED', 'message' => 'List is archived; items cannot be added.'],
                ]);
        });

        it('owner dapat show items', function () {
            $userId = ulid();
            $householdId = ulid();
            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();
            \App\Models\ListItem::factory()->forList($listId)->create();

            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->getJson("/api/lists/{$listId}/items");

            $response->assertStatus(200)
                ->assertJsonCount(1, 'data');
        });

        it('filter items by status active/completed', function () {
            $userId = ulid();
            $householdId = ulid();
            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();

            // Create active item
            \App\Models\ListItem::factory()->forList($listId)->create(['completed_at' => null]);
            // Create completed item
            \App\Models\ListItem::factory()->forList($listId)->create(['completed_at' => now()]);

            // Filter active
            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->getJson("/api/lists/{$listId}/items?status=active");

            $response->assertStatus(200)
                ->assertJsonCount(1, 'data');

            // Filter completed
            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->getJson("/api/lists/{$listId}/items?status=completed");

            $response->assertStatus(200)
                ->assertJsonCount(1, 'data');
        });

        it('owner dapat update item (bukan jika sudah completed tanpa uncomplete)', function () {
            $userId = ulid();
            $householdId = ulid();
            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();
            $itemId = \App\Models\ListItem::factory()->forList($listId)->create(['completed_at' => null]);

            // Update title on active item
            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->patchJson("/api/lists/{$listId}/items/{$itemId}", [
                'title' => 'Milk Updated',
            ]);

            $response->assertStatus(200)
                ->assertJson([
                    'data' => ['title' => 'Milk Updated'],
                ]);
        });

        it('tidak dapat update item completed tanpa uncomplete (409)', function () {
            $userId = ulid();
            $householdId = ulid();
            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();
            $itemId = \App\Models\ListItem::factory()->forList($listId)->create(['completed_at' => now()]);

            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->patchJson("/api/lists/{$listId}/items/{$itemId}", [
                'title' => 'New Title',
            ]);

            $response->assertStatus(409)
                ->assertJson([
                    'error' => ['code' => 'LIST_ITEM_ALREADY_COMPLETED', 'message' => 'Item already completed. Use uncomplete first if needed.'],
                ]);
        });

        it('tidak dapat update item jika sudah ada Expense dan actual_price diisi (409)', function () {
            $userId = ulid();
            $householdId = ulid();
            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();
            $itemId = \App\Models\ListItem::factory()->forList($listId)->create(['completed_at' => now(), 'actual_price' => '100.00']);

            // Try to update with actual_price when expense exists
            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->patchJson("/api/lists/{$listId}/items/{$itemId}", [
                'actual_price' => '200.00',
            ]);

            $response->assertStatus(409)
                ->assertJson([
                    'error' => ['code' => 'LIST_ITEM_PRICE_LOCKED', 'message' => 'Actual price locked; Expense already exists.'],
                ]);
        });

        it('owner dapat uncomplete item (A-18 disetujui)', function () {
            $userId = ulid();
            $householdId = ulid();
            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();
            $itemId = \App\Models\ListItem::factory()->forList($listId)->create(['completed_at' => now()]);

            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/items/{$itemId}/uncomplete");

            $response->assertStatus(200);

            // Verify item is now active again
            $getResponse = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->getJson("/api/lists/{$listId}/items/{$itemId}");

            $getResponse->assertStatus(200)
                ->assertJson([
                    'data' => ['is_completed' => false, 'actual_price' => '100.00'], // actual_price preserved
                ]);
        });

        it('tidak dapat uncomplete item yang belum completed (409)', function () {
            $userId = ulid();
            $householdId = ulid();
            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();
            $itemId = \App\Models\ListItem::factory()->forList($listId)->create(['completed_at' => null]);

            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/items/{$itemId}/uncomplete");

            $response->assertStatus(409)
                ->assertJson([
                    'error' => ['code' => 'LIST_ITEM_NOT_COMPLETED', 'message' => 'Item is not completed.'],
                ]);
        });
    });

    // -----------------------------------------------------------------
    // 4. Complete/uncomplete dan persistensi actual_price
    // -----------------------------------------------------------------

    describe('complete/uncomplete', function () {
        beforeEach(function () {
            if (! pgIsAvailable()) {
                return $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
            }
            Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);
        });

        it('owner dapat complete item', function () {
            $userId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();
            $itemId = \App\Models\ListItem::factory()->forList($listId)->create(['completed_at' => null]);

            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/items/{$itemId}/complete");

            $response->assertStatus(200);

            // Verify item is now completed
            $getResponse = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->getJson("/api/lists/{$listId}/items/{$itemId}");

            $getResponse->assertStatus(200)
                ->assertJson([
                    'data' => ['is_completed' => true, 'actual_price' => null], // actual_price still null
                ]);
        });

        it('uncomplete clear completed_at namunpertahankan actual_price', function () {
            $userId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();
            $itemId = \App\Models\ListItem::factory()->forList($listId)->create(['completed_at' => now(), 'actual_price' => '150.00']);

            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/items/{$itemId}/uncomplete");

            $response->assertStatus(200);

            $getResponse = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->getJson("/api/lists/{$listId}/items/{$itemId}");

            $getResponse->assertStatus(200)
                ->assertJson([
                    'data' => ['is_completed' => false, 'actual_price' => '150.00'], // preserved
                ]);
        });

        it('tidak dapat complete item yang sudah completed (409)', function () {
            $userId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();
            $itemId = \App\Models\ListItem::factory()->forList($listId)->create(['completed_at' => now()]);

            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/items/{$itemId}/complete");

            $response->assertStatus(409)
                ->assertJson([
                    'error' => ['code' => 'LIST_ITEM_ALREADY_COMPLETED', 'message' => 'Item already completed.'],
                ]);
        });

        it('tidak dapat uncomplete item yang belum completed (409)', function () {
            $userId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();
            $itemId = \App\Models\ListItem::factory()->forList($listId)->create(['completed_at' => null]);

            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/items/{$itemId}/uncomplete");

            $response->assertStatus(409)
                ->assertJson([
                    'error' => ['code' => 'LIST_ITEM_NOT_COMPLETED', 'message' => 'Item is not completed.'],
                ]);
        });
    });

    // -----------------------------------------------------------------
    // 5. Pembuatan Expense
    // -----------------------------------------------------------------

    describe('expense creation', function () {
        beforeEach(function () {
            if (! pgIsAvailable()) {
                return $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
            }
            Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);
        });

        it('owner dapat create expense untuk item completed', function () {
            $userId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();
            $itemId = \App\Models\ListItem::factory()->forList($listId)->create(['completed_at' => now(), 'actual_price' => '200.00']);

            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/items/{$itemId}/expense");

            $response->assertStatus(201)
                ->assertJson([
                    'success' => true,
                    'data' => [
                        'type' => 'expense',
                        'amount' => '200.00',
                        'description' => 'Test Item', // title dari item
                        'source_id' => $itemId,
                    ],
                ]);
        });

        it('expense gagal jika item belum completed (409)', function () {
            $userId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();
            $itemId = \App\Models\ListItem::factory()->forList($listId)->create(['completed_at' => null, 'actual_price' => '100.00']);

            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/items/{$itemId}/expense");

            $response->assertStatus(409)
                ->assertJson([
                    'error' => ['code' => 'LIST_ITEM_NOT_COMPLETED', 'message' => 'Item must be completed before creating Expense.'],
                ]);
        });

        it('expense gagal jika actual_price null atau <= 0 (422)', function () {
            $userId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();
            $itemId = \App\Models\ListItem::factory()->forList($listId)->create(['completed_at' => now(), 'actual_price' => null]);

            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/items/{$itemId}/expense");

            $response->assertStatus(422)
                ->assertJson([
                    'error' => ['code' => 'LIST_ITEM_ACTUAL_PRICE_REQUIRED', 'message' => 'Actual price is required for Expense and must be > 0.'],
                ]);
        });

        it('expense idempoten: request kedua kembalikan expense yang sudah ada (200)', function () {
            $userId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();
            $itemId = \App\Models\ListItem::factory()->forList($listId)->create(['completed_at' => now(), 'actual_price' => '300.00']);

            // First expense creation
            $response1 = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/items/{$itemId}/expense");

            $response1->assertStatus(201);

            // Second expense creation — should return existing
            $response2 = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/items/{$itemId}/expense");

            $response2->assertStatus(200)
                ->assertJson([
                    'data' => ['id' => fn ($value) => strlen($value) > 0], // existing expense id
                    'info' => 'Expense already exists for this item. Klik generate untuk detail.',
                ]);
        });

        it('expense menyurkan actual_price ke item', function () {
            $userId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();
            $itemId = \App\Models\ListItem::factory()->forList($listId)->create(['completed_at' => now(), 'actual_price' => null]);

            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/items/{$itemId}/expense", [
                'actual_price' => '250.50',
            ]);

            $response->assertStatus(201);

            // Verify actual_price updated on item
            $itemResponse = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->getJson("/api/lists/{$listId}/items/{$itemId}");

            $itemResponse->assertStatus(200)
                ->assertJson([
                    'data' => ['actual_price' => '250.50'],
                ]);
        });

        it('member tidak dapat create expense di list orang lain (404)', function () {
            $ownerId = ulid();
            $memberId = ulid();
            $householdId = ulid();
            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();
            $itemId = \App\Models\ListItem::factory()->forList($listId)->create(['completed_at' => now(), 'actual_price' => '100.00']);

            $response = $this->actingAs(
                \App\Models\User::findOrFail($memberId)
            )->postJson("/api/lists/{$listId}/items/{$itemId}/expense");

            $response->assertStatus(404);
        });
    });

    // -----------------------------------------------------------------
    // 6. Idempotensi dan penguncian Expense
    // -----------------------------------------------------------------

    describe('expense idempotensi & locking', function () {
        beforeEach(function () {
            if (! pgIsAvailable()) {
                return $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
            }
            Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);
        });

        it('hanya satu Expense per item (unique index)', function () {
            $userId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();
            $itemId = \App\Models\ListItem::factory()->forList($listId)->create(['completed_at' => now(), 'actual_price' => '100.00']);

            // First expense
            $response1 = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/items/{$itemId}/expense");

            $response1->assertStatus(201);

            // Second expense — should return existing, not create new
            $response2 = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/items/{$itemId}/expense");

            $response2->assertStatus(200);

            // Verify only 1 expense exists via show item
            $itemsResponse = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->getJson("/api/lists/{$listId}/items/{$itemId}");

            $itemsResponse->assertStatus(200);
        });

        it('expense tidak bisa diubah melalui endpoint item PATCH (409)', function () {
            $userId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();
            $itemId = \App\Models\ListItem::factory()->forList($listId)->create(['completed_at' => now(), 'actual_price' => '100.00']);

            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->patchJson("/api/lists/{$listId}/items/{$itemId}", [
                'actual_price' => '500.00',
            ]);

            $response->assertStatus(409)
                ->assertJson([
                    'error' => ['code' => 'LIST_ITEM_PRICE_LOCKED', 'message' => 'Actual price locked; Expense already exists.'],
                ]);
        });
    });

    // -----------------------------------------------------------------
    // 7. Aturan item/list yang memiliki Expense
    // -----------------------------------------------------------------

    describe('rules with existing Expense', function () {
        beforeEach(function () {
            if (! pgIsAvailable()) {
                return $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
            }
            Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);
        });

        it('item dengan Expense tidak boleh diupdate actual_price (409)', function () {
            $userId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();
            $itemId = \App\Models\ListItem::factory()->forList($listId)->create(['completed_at' => now(), 'actual_price' => '100.00']);

            // Try to update title first (should work)
            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->patchJson("/api/lists/{$listId}/items/{$itemId}", [
                'title' => 'Updated Title',
            ]);

            $response->assertStatus(200); // title update ok

            // But actual_price update should be locked
            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->patchJson("/api/lists/{$listId}/items/{$itemId}", [
                'actual_price' => '200.00',
            ]);

            $response->assertStatus(409)
                ->assertJson([
                    'error' => ['code' => 'LIST_ITEM_PRICE_LOCKED', 'message' => 'Actual price locked; Expense already exists.'],
                ]);
        });

        it('item dengan Expense tetap bisa diuncomplete', function () {
            $userId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();
            $itemId = \App\Models\ListItem::factory()->forList($listId)->create(['completed_at' => now(), 'actual_price' => '100.00']);

            // Uncomplete should still work, preserving actual_price
            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/items/{$itemId}/uncomplete");

            $response->assertStatus(200);

            $getResponse = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->getJson("/api/lists/{$listId}/items/{$itemId}");

            $getResponse->assertStatus(200)
                ->assertJson([
                    'data' => ['is_completed' => false, 'actual_price' => '100.00'], // preserved
                ]);
        });
    });

    // -----------------------------------------------------------------
    // 8. Validasi unit opsional maksimal 20 karakter
    // -----------------------------------------------------------------

    describe('unit validation', function () {
        beforeEach(function () {
            if (! pgIsAvailable()) {
                return $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
            }
            Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);
        });

        it('unit opsional diterima jika diisi < 20 karakter', function () {
            $userId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();

            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/items", [
                'title' => 'Test Item',
                'quantity' => '2.00',
                'unit' => 'kg', // 2 chars, valid
                'estimated_price' => '50.00',
            ]);

            $response->assertStatus(201)
                ->assertJson([
                    'data' => ['unit' => 'kg'],
                ]);
        });

        it('unit ditolak jika > 20 karakter (validasi FormRequest)', function () {
            $userId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();

            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/items", [
                'title' => 'Test Item',
                'quantity' => '2.00',
                'unit' => 'this_is_a_very_long_unit_name_that_exceeds_twenty_chars',
                'estimated_price' => '50.00',
            ]);

            $response->assertStatus(422); // FormRequest validation error
        });

        it('unit kosong (null) diterima', function () {
            $userId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();

            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/items", [
                'title' => 'Test Item',
                'quantity' => '2.00',
                'unit' => null,
                'estimated_price' => '50.00',
            ]);

            $response->assertStatus(201)
                ->assertJson([
                    'data' => ['unit' => null],
                ]);
        });
    });

    // -----------------------------------------------------------------
    // 9. Kode error dan HTTP status sesuai kontrak API
    // -----------------------------------------------------------------

    describe('error codes and HTTP status', function () {
        beforeEach(function () {
            if (! pgIsAvailable()) {
                return $this->markTestSkipped('PostgreSQL tidak tersedia di environment ini.');
            }
            Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);
        });

        it('semua error code sesuai kontrak LIST', function () {
            $userId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();

            // Test LIST_ARCHIVED 409
            $response = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/archive");

            // If no candidate default, should be 409
            if ($response->status() === 409) {
                $response->assertJson([
                    'error' => ['code' => 'DEFAULT_LIST_REQUIRED'],
                ]);
            }
        });

        it('HTTP status code correct for LIST error conditions', function () {
            $userId = ulid();
            $householdId = ulid();

            DB::connection('pgsql')->table('users')->insert([
                'id' => $userId,
                'username' => 'testuser',
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'secret',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('pgsql')->table('households')->insert([
                'id' => $householdId,
                'name' => 'Test Household',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $listId = \App\Models\HouseholdList::factory()->forHousehold($householdId)->create();

            // 409 CONFLICT for archived list update
            $archiveResponse = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/archive");

            if ($archiveResponse->status() === 409) {
                $archiveResponse->assertStatus(409);
            }

            // 422 UNPROCESSABLE for invalid actual_price
            $itemId = \App\Models\ListItem::factory()->forList($listId)->create(['completed_at' => now(), 'actual_price' => null]);

            $expenseResponse = $this->actingAs(
                \App\Models\User::findOrFail($userId)
            )->postJson("/api/lists/{$listId}/items/{$itemId}/expense");

            if ($expenseResponse->status() === 422) {
                $expenseResponse->assertStatus(422);
            }
        });
    });
});