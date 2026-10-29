<?php

use App\Models\Household;
use App\Models\HouseholdList;
use App\Models\HouseholdMember;
use App\Models\ListItem;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lists Feature Tests — Homflo Phase 16.6 / 16.6.11
 *
 * End-to-end coverage of the Lists contract: default list per household,
 * CRUD Lists, CRUD List Items, complete/uncomplete, Expense creation,
 * and owner/member authorization with 404 masking for cross-household
 * access.
 *
 * The suite runs against the configured default connection. Under the
 * Phase 16.6.11 PostgreSQL run that connection is real PostgreSQL; under
 * the repository's default phpunit.xml it is SQLite. No test is skipped
 * based on driver availability.
 *
 * @group lists
 */
function legacyListsHousehold(User $owner, string $role = 'owner', string $name = 'Rumah Lists'): Household
{
    $household = Household::create([
        'name' => $name,
        'created_by' => $owner->id,
    ]);

    HouseholdMember::create([
        'household_id' => $household->id,
        'user_id' => $owner->id,
        'role' => $role,
    ]);

    return $household->refresh();
}

function legacyListsJoin(Household $household, string $role = 'member'): User
{
    $user = User::factory()->create();

    HouseholdMember::create([
        'household_id' => $household->id,
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function legacyListsList(Household $household, array $overrides = []): HouseholdList
{
    return HouseholdList::create(array_merge([
        'household_id' => $household->id,
        'name' => 'Daftar Belanja',
        'type' => 'shopping',
        'created_by' => $household->created_by,
        'archived_at' => null,
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function legacyListsItem(HouseholdList $list, array $overrides = []): ListItem
{
    return ListItem::create(array_merge([
        'list_id' => $list->id,
        'title' => 'Test Item',
        'quantity' => null,
        'unit' => null,
        'estimated_price' => null,
        'actual_price' => null,
        'completed_at' => null,
        'completed_by' => null,
        'created_by' => $list->created_by,
    ], $overrides));
}

function legacyListsWallet(Household $household, string $openingBalance = '100000.00'): Wallet
{
    return Wallet::create([
        'household_id' => $household->id,
        'name' => 'Tunai',
        'type' => Wallet::TYPE_CASH,
        'opening_balance' => $openingBalance,
        'is_active' => true,
    ]);
}

function legacyListsExpense(ListItem $item, Household $household, Wallet $wallet): Transaction
{
    return Transaction::create([
        'household_id' => $household->id,
        'wallet_id' => $wallet->id,
        'type' => Transaction::TYPE_EXPENSE,
        'amount' => $item->actual_price ?? '100.00',
        'occurred_on' => now()->toDateString(),
        'description' => $item->title,
        'source_type' => ListItem::class,
        'source_id' => $item->id,
    ]);
}

function legacyListsSetDefault(Household $household, ?string $listId): void
{
    $household->default_list_id = $listId;
    $household->save();
    $household->refresh();
}

describe('Lists', function () {
    // -----------------------------------------------------------------
    // 1. Default List: Pembuatan, Pointer Household, dan Invariant Satu Default
    // -----------------------------------------------------------------

    describe('default list', function () {
        it('owner dapat membuat list dan menjadi default otomatis', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);

            $createResponse = $this->actingAs($owner)->postJson('/api/lists', [
                'name' => 'My Shopping List',
                'type' => 'shopping',
            ]);

            $createResponse->assertStatus(201)
                ->assertJsonStructure([
                    'success',
                    'data' => ['id', 'name', 'type', 'is_default', 'counts'],
                ]);

            $listId = $createResponse->json('data.id');

            $this->actingAs($owner)
                ->getJson('/api/lists/default')
                ->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'data' => ['id' => $listId, 'is_default' => true],
                ]);

            $this->actingAs($owner)
                ->getJson('/api/lists')
                ->assertStatus(200)
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.is_default', true);

            expect($household->refresh()->default_list_id)->toBe($listId);
        });

        it('member dapat membuat list dan melihat list household (kontrak §11.1)', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $member = legacyListsJoin($household);

            $ownerList = legacyListsList($household, ['name' => 'Daftar Owner']);
            legacyListsSetDefault($household, $ownerList->id);

            $response = $this->actingAs($member)->postJson('/api/lists', [
                'name' => 'Daftar Member',
                'type' => 'custom',
            ]);

            $response->assertStatus(201)
                ->assertJsonPath('data.name', 'Daftar Member')
                ->assertJsonPath('data.type', 'custom')
                ->assertJsonPath('data.is_default', false)
                ->assertJsonPath('data.created_by.id', $member->id);

            $this->actingAs($member)
                ->getJson('/api/lists')
                ->assertStatus(200)
                ->assertJsonCount(2, 'data');

            $this->actingAs($member)
                ->getJson("/api/lists/{$ownerList->id}")
                ->assertStatus(200)
                ->assertJsonPath('data.id', $ownerList->id);
        });
    });

    // -----------------------------------------------------------------
    // 2. CRUD Lists dan aturan archive/restore
    // -----------------------------------------------------------------

    describe('CRUD Lists', function () {
        it('owner dapat create list', function () {
            $owner = User::factory()->create();
            legacyListsHousehold($owner);

            $response = $this->actingAs($owner)->postJson('/api/lists', [
                'name' => 'Bills List',
                'type' => 'shopping',
            ]);

            $response->assertStatus(201)
                ->assertJson([
                    'success' => true,
                    'data' => [
                        'name' => 'Bills List',
                        'type' => 'shopping',
                        'is_default' => true,
                        'counts' => ['active' => 0, 'completed' => 0],
                    ],
                ])
                ->assertJsonPath('data.created_by.id', $owner->id);
        });

        it('owner dapat show list detail', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $list = legacyListsList($household);

            $this->actingAs($owner)
                ->getJson("/api/lists/{$list->id}")
                ->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'data' => [
                        'id' => $list->id,
                        'household_id' => $household->id,
                        'type' => 'shopping',
                    ],
                ]);
        });

        it('member tidak dapat akses list owner lain (404 masking)', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $list = legacyListsList($household);

            $intruder = User::factory()->create();
            legacyListsHousehold($intruder, 'owner', 'Rumah Lain');

            $this->actingAs($intruder)
                ->getJson("/api/lists/{$list->id}")
                ->assertStatus(404)
                ->assertJsonPath('error.code', 'LIST_NOT_FOUND');
        });

        it('owner dapat update nama list (bukan archived)', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $list = legacyListsList($household);

            $this->actingAs($owner)
                ->patchJson("/api/lists/{$list->id}", ['name' => 'Updated List Name'])
                ->assertStatus(200)
                ->assertJsonPath('data.name', 'Updated List Name');

            expect($list->refresh()->name)->toBe('Updated List Name');
        });

        it('owner cannot update archived list (409)', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $list = legacyListsList($household, ['type' => 'custom']);

            $this->actingAs($owner)
                ->postJson("/api/lists/{$list->id}/archive")
                ->assertStatus(200);

            $this->actingAs($owner)
                ->patchJson("/api/lists/{$list->id}", ['name' => 'New Name'])
                ->assertStatus(409)
                ->assertJson([
                    'error' => [
                        'code' => 'LIST_ARCHIVED',
                        'message' => 'List is archived and read-only.',
                    ],
                ]);

            expect($list->refresh()->archived_at)->not->toBeNull();
            expect($list->name)->not->toBe('New Name');
        });

        it('owner dapat archive list (kandida default harus ada)', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $list = legacyListsList($household);
            legacyListsSetDefault($household, $list->id);

            $this->actingAs($owner)
                ->postJson("/api/lists/{$list->id}/archive")
                ->assertStatus(409)
                ->assertJson([
                    'error' => [
                        'code' => 'DEFAULT_LIST_REQUIRED',
                        'message' => 'Household must have a default active Shopping List.',
                    ],
                ]);

            expect($list->refresh()->archived_at)->toBeNull();
        });

        it('owner dapat restore archived list', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $list = legacyListsList($household, ['archived_at' => now()]);

            $this->actingAs($owner)
                ->postJson("/api/lists/{$list->id}/restore")
                ->assertStatus(200)
                ->assertJsonPath('data.archived_at', null);

            expect($list->refresh()->archived_at)->toBeNull();
        });
    });

    // -----------------------------------------------------------------
    // 3. CRUD List Items
    // -----------------------------------------------------------------

    describe('CRUD List Items', function () {
        it('owner dapat create item di list aktif', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $list = legacyListsList($household);

            $response = $this->actingAs($owner)->postJson("/api/lists/{$list->id}/items", [
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
                        'status' => 'active',
                        'completed_by' => null,
                        'expense' => null,
                    ],
                ])
                ->assertJsonPath('data.created_by.id', $owner->id);
        });

        it('member tidak dapat create item di list orang lain (404)', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $list = legacyListsList($household);

            $intruder = User::factory()->create();
            legacyListsHousehold($intruder, 'owner', 'Rumah Lain');

            $this->actingAs($intruder)
                ->postJson("/api/lists/{$list->id}/items", [
                    'title' => 'Unauthorized Item',
                    'quantity' => '1.00',
                    'unit' => 'pcs',
                    'estimated_price' => '10.00',
                ])
                ->assertStatus(404)
                ->assertJsonPath('error.code', 'LIST_NOT_FOUND');

            expect(ListItem::count())->toBe(0);
        });

        it('tidak dapat create item di list archived (409)', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $list = legacyListsList($household, ['archived_at' => now()]);

            $this->actingAs($owner)
                ->postJson("/api/lists/{$list->id}/items", [
                    'title' => 'New Item',
                    'quantity' => '1.00',
                    'unit' => 'pcs',
                    'estimated_price' => '10.00',
                ])
                ->assertStatus(409)
                ->assertJson([
                    'error' => [
                        'code' => 'LIST_ARCHIVED',
                        'message' => 'List is archived; items cannot be added.',
                    ],
                ]);
        });

        it('owner dapat show items', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $list = legacyListsList($household);
            $item = legacyListsItem($list);

            $this->actingAs($owner)
                ->getJson("/api/lists/{$list->id}/items")
                ->assertStatus(200)
                ->assertJsonPath('data.list_id', $list->id)
                ->assertJsonPath('data.counts.active', 1)
                ->assertJsonPath('data.counts.completed', 0)
                ->assertJsonCount(1, 'data.active')
                ->assertJsonPath('data.active.0.id', $item->id);
        });

        it('filter items by status active/completed', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $list = legacyListsList($household);

            $active = legacyListsItem($list, ['completed_at' => null]);
            $completed = legacyListsItem($list, ['completed_at' => now()]);

            $response = $this->actingAs($owner)
                ->getJson("/api/lists/{$list->id}/items");

            $response->assertStatus(200)
                ->assertJsonPath('data.counts.active', 1)
                ->assertJsonPath('data.counts.completed', 1)
                ->assertJsonCount(1, 'data.active')
                ->assertJsonCount(1, 'data.completed')
                ->assertJsonPath('data.active.0.id', $active->id)
                ->assertJsonPath('data.active.0.status', 'active')
                ->assertJsonPath('data.completed.0.id', $completed->id)
                ->assertJsonPath('data.completed.0.status', 'completed')
                ->assertJsonMissingPath('data.active.0.is_completed');
        });

        it('owner dapat update item (bukan jika sudah completed tanpa uncomplete)', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $list = legacyListsList($household);
            $item = legacyListsItem($list, ['completed_at' => null]);

            $this->actingAs($owner)
                ->patchJson("/api/lists/{$list->id}/items/{$item->id}", [
                    'title' => 'Milk Updated',
                ])
                ->assertStatus(200)
                ->assertJsonPath('data.title', 'Milk Updated');

            expect($item->refresh()->title)->toBe('Milk Updated');
        });

        it('tidak dapat update item completed tanpa uncomplete (409)', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $list = legacyListsList($household);
            $item = legacyListsItem($list, ['completed_at' => now()]);

            $this->actingAs($owner)
                ->patchJson("/api/lists/{$list->id}/items/{$item->id}", [
                    'title' => 'New Title',
                ])
                ->assertStatus(409)
                ->assertJson([
                    'error' => [
                        'code' => 'LIST_ITEM_ALREADY_COMPLETED',
                        'message' => 'Item already completed. Use uncomplete first if needed.',
                    ],
                ]);

            expect($item->refresh()->title)->not->toBe('New Title');
        });

        it('tidak dapat update item jika sudah ada Expense dan actual_price diisi (409)', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $wallet = legacyListsWallet($household);
            $list = legacyListsList($household);
            $item = legacyListsItem($list, [
                'completed_at' => now(),
                'actual_price' => '100.00',
            ]);
            legacyListsExpense($item, $household, $wallet);

            $this->actingAs($owner)
                ->patchJson("/api/lists/{$list->id}/items/{$item->id}", [
                    'actual_price' => '200.00',
                ])
                ->assertStatus(409)
                ->assertJson([
                    'error' => [
                        'code' => 'LIST_ITEM_PRICE_LOCKED',
                        'message' => 'Actual price locked; Expense already exists.',
                    ],
                ]);

            expect($item->refresh()->actual_price)->toBe('100.00');
        });

        it('owner dapat uncomplete item (A-18 disetujui)', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $list = legacyListsList($household);
            $item = legacyListsItem($list, [
                'completed_at' => now(),
                'completed_by' => $owner->id,
                'actual_price' => '100.00',
            ]);

            $this->actingAs($owner)
                ->postJson("/api/lists/{$list->id}/items/{$item->id}/uncomplete")
                ->assertStatus(200)
                ->assertJsonPath('data.status', 'active')
                ->assertJsonPath('data.completed_at', null)
                ->assertJsonPath('data.actual_price', '100.00');

            $this->actingAs($owner)
                ->getJson("/api/lists/{$list->id}/items")
                ->assertStatus(200)
                ->assertJsonPath('data.counts.active', 1)
                ->assertJsonPath('data.counts.completed', 0);
        });

        it('tidak dapat uncomplete item yang belum completed (409)', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $list = legacyListsList($household);
            $item = legacyListsItem($list, ['completed_at' => null]);

            $this->actingAs($owner)
                ->postJson("/api/lists/{$list->id}/items/{$item->id}/uncomplete")
                ->assertStatus(409)
                ->assertJson([
                    'error' => [
                        'code' => 'LIST_ITEM_NOT_COMPLETED',
                        'message' => 'Item is not completed.',
                    ],
                ]);
        });
    });

    // -----------------------------------------------------------------
    // 4. Complete/uncomplete dan persistensi actual_price
    // -----------------------------------------------------------------

    describe('complete/uncomplete', function () {
        it('owner dapat complete item', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $list = legacyListsList($household);
            $item = legacyListsItem($list, ['completed_at' => null]);

            $this->actingAs($owner)
                ->postJson("/api/lists/{$list->id}/items/{$item->id}/complete")
                ->assertStatus(200)
                ->assertJsonPath('data.status', 'completed')
                ->assertJsonPath('data.actual_price', null)
                ->assertJsonPath('data.completed_by.id', $owner->id);

            expect($item->refresh()->completed_at)->not->toBeNull();
            expect(Transaction::count())->toBe(0);
        });

        it('uncomplete clear completed_at namunpertahankan actual_price', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $list = legacyListsList($household);
            $item = legacyListsItem($list, [
                'completed_at' => now(),
                'completed_by' => $owner->id,
                'actual_price' => '150.00',
            ]);

            $this->actingAs($owner)
                ->postJson("/api/lists/{$list->id}/items/{$item->id}/uncomplete")
                ->assertStatus(200)
                ->assertJsonPath('data.status', 'active')
                ->assertJsonPath('data.completed_at', null)
                ->assertJsonPath('data.actual_price', '150.00');

            expect($item->refresh()->actual_price)->toBe('150.00');
            expect($item->completed_at)->toBeNull();
        });

        it('tidak dapat complete item yang sudah completed (409)', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $list = legacyListsList($household);
            $item = legacyListsItem($list, ['completed_at' => now()]);

            $this->actingAs($owner)
                ->postJson("/api/lists/{$list->id}/items/{$item->id}/complete")
                ->assertStatus(409)
                ->assertJson([
                    'error' => [
                        'code' => 'LIST_ITEM_ALREADY_COMPLETED',
                        'message' => 'Item already completed.',
                    ],
                ]);
        });

        it('tidak dapat uncomplete item yang belum completed (409)', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $list = legacyListsList($household);
            $item = legacyListsItem($list, ['completed_at' => null]);

            $this->actingAs($owner)
                ->postJson("/api/lists/{$list->id}/items/{$item->id}/uncomplete")
                ->assertStatus(409)
                ->assertJson([
                    'error' => [
                        'code' => 'LIST_ITEM_NOT_COMPLETED',
                        'message' => 'Item is not completed.',
                    ],
                ]);
        });
    });

    // -----------------------------------------------------------------
    // 5. Pembuatan Expense
    // -----------------------------------------------------------------

    describe('expense creation', function () {
        it('owner dapat create expense untuk item completed', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $wallet = legacyListsWallet($household);
            $list = legacyListsList($household);
            $item = legacyListsItem($list, [
                'title' => 'Test Item',
                'completed_at' => now(),
                'actual_price' => '200.00',
            ]);

            $response = $this->actingAs($owner)
                ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                    'wallet_id' => $wallet->id,
                ]);

            $response->assertStatus(201)
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.created', true)
                ->assertJsonPath('data.expense.type', 'expense')
                ->assertJsonPath('data.expense.amount', '200.00')
                ->assertJsonPath('data.expense.description', 'Test Item')
                ->assertJsonPath('data.expense.wallet_id', $wallet->id)
                ->assertJsonPath('data.item.id', $item->id)
                ->assertJsonPath('data.item.expense.id', $response->json('data.expense.id'));

            $expense = Transaction::findOrFail($response->json('data.expense.id'));

            expect($expense->source_type)->toBe(ListItem::class);
            expect($expense->source_id)->toBe($item->id);
            expect(Transaction::count())->toBe(1);
        });

        it('expense gagal jika item belum completed (409)', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $wallet = legacyListsWallet($household);
            $list = legacyListsList($household);
            $item = legacyListsItem($list, [
                'completed_at' => null,
                'actual_price' => '100.00',
            ]);

            $this->actingAs($owner)
                ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                    'wallet_id' => $wallet->id,
                ])
                ->assertStatus(409)
                ->assertJson([
                    'error' => [
                        'code' => 'LIST_ITEM_NOT_COMPLETED',
                        'message' => 'Item must be completed before creating Expense.',
                    ],
                ]);

            expect(Transaction::count())->toBe(0);
        });

        it('expense gagal jika actual_price null atau <= 0 (422)', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $wallet = legacyListsWallet($household);
            $list = legacyListsList($household);
            $item = legacyListsItem($list, [
                'completed_at' => now(),
                'actual_price' => null,
            ]);

            $this->actingAs($owner)
                ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                    'wallet_id' => $wallet->id,
                ])
                ->assertStatus(422)
                ->assertJson([
                    'error' => [
                        'code' => 'LIST_ITEM_ACTUAL_PRICE_REQUIRED',
                        'message' => 'Actual price is required for Expense and must be > 0.',
                    ],
                ]);

            $this->actingAs($owner)
                ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                    'wallet_id' => $wallet->id,
                    'actual_price' => '0',
                ])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'LIST_ITEM_ACTUAL_PRICE_REQUIRED');

            expect(Transaction::count())->toBe(0);
            expect($item->refresh()->actual_price)->toBeNull();
        });

        it('expense idempoten: request kedua kembalikan expense yang sudah ada (200)', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $wallet = legacyListsWallet($household);
            $list = legacyListsList($household);
            $item = legacyListsItem($list, [
                'completed_at' => now(),
                'actual_price' => '300.00',
            ]);

            $first = $this->actingAs($owner)
                ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                    'wallet_id' => $wallet->id,
                ])
                ->assertStatus(201)
                ->assertJsonPath('data.created', true);

            $expenseId = $first->json('data.expense.id');

            $this->actingAs($owner)
                ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                    'wallet_id' => $wallet->id,
                ])
                ->assertStatus(200)
                ->assertJsonPath('data.created', false)
                ->assertJsonPath('data.expense.id', $expenseId)
                ->assertJsonPath('data.item.expense.id', $expenseId);

            expect(Transaction::count())->toBe(1);
        });

        it('expense menyurkan actual_price ke item', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $wallet = legacyListsWallet($household);
            $list = legacyListsList($household);
            $item = legacyListsItem($list, [
                'completed_at' => now(),
                'actual_price' => null,
            ]);

            $this->actingAs($owner)
                ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                    'wallet_id' => $wallet->id,
                    'actual_price' => '250.50',
                ])
                ->assertStatus(201)
                ->assertJsonPath('data.expense.amount', '250.50')
                ->assertJsonPath('data.item.actual_price', '250.50');

            expect($item->refresh()->actual_price)->toBe('250.50');
        });

        it('member tidak dapat create expense di list orang lain (404)', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $list = legacyListsList($household);
            $item = legacyListsItem($list, [
                'completed_at' => now(),
                'actual_price' => '100.00',
            ]);

            $intruder = User::factory()->create();
            $intruderHousehold = legacyListsHousehold($intruder, 'owner', 'Rumah Lain');
            $intruderWallet = legacyListsWallet($intruderHousehold);

            $this->actingAs($intruder)
                ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                    'wallet_id' => $intruderWallet->id,
                ])
                ->assertStatus(404)
                ->assertJsonPath('error.code', 'LIST_ITEM_NOT_FOUND');

            expect(Transaction::count())->toBe(0);
        });
    });

    // -----------------------------------------------------------------
    // 6. Idempotensi dan penguncian Expense
    // -----------------------------------------------------------------

    describe('expense idempotensi & locking', function () {
        it('hanya satu Expense per item (unique index)', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $wallet = legacyListsWallet($household);
            $list = legacyListsList($household);
            $item = legacyListsItem($list, [
                'completed_at' => now(),
                'actual_price' => '100.00',
            ]);

            $expenseId = $this->actingAs($owner)
                ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                    'wallet_id' => $wallet->id,
                ])
                ->assertStatus(201)
                ->json('data.expense.id');

            // A second API call is idempotent and never writes a row.
            $this->actingAs($owner)
                ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                    'wallet_id' => $wallet->id,
                ])
                ->assertStatus(200)
                ->assertJsonPath('data.expense.id', $expenseId);

            // The partial unique index is the final database-level guard.
            // The nested transaction gives PostgreSQL a savepoint so the
            // expected constraint violation cannot poison the outer
            // RefreshDatabase transaction.
            try {
                DB::transaction(function () use ($household, $wallet, $item): void {
                    DB::table('transactions')->insert([
                        'id' => (string) Str::ulid(),
                        'household_id' => $household->id,
                        'wallet_id' => $wallet->id,
                        'type' => Transaction::TYPE_EXPENSE,
                        'amount' => '100.00',
                        'occurred_on' => now()->toDateString(),
                        'description' => 'Duplicate Expense',
                        'source_type' => ListItem::class,
                        'source_id' => $item->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });

                $this->fail('Expected the unique index to reject a duplicate item Expense.');
            } catch (QueryException) {
                expect(Transaction::count())->toBe(1);
            }
        });

        it('expense tidak bisa diubah melalui endpoint item PATCH (409)', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $wallet = legacyListsWallet($household);
            $list = legacyListsList($household);
            $item = legacyListsItem($list, [
                'completed_at' => now(),
                'actual_price' => '100.00',
            ]);
            legacyListsExpense($item, $household, $wallet);

            $this->actingAs($owner)
                ->patchJson("/api/lists/{$list->id}/items/{$item->id}", [
                    'actual_price' => '500.00',
                ])
                ->assertStatus(409)
                ->assertJson([
                    'error' => [
                        'code' => 'LIST_ITEM_PRICE_LOCKED',
                        'message' => 'Actual price locked; Expense already exists.',
                    ],
                ]);

            expect($item->refresh()->actual_price)->toBe('100.00');
        });
    });

    // -----------------------------------------------------------------
    // 7. Aturan item/list yang memiliki Expense
    // -----------------------------------------------------------------

    describe('rules with existing Expense', function () {
        it('item dengan Expense tidak boleh diupdate actual_price (409)', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $wallet = legacyListsWallet($household);
            $list = legacyListsList($household);
            $item = legacyListsItem($list, [
                'completed_at' => now(),
                'actual_price' => '100.00',
            ]);
            legacyListsExpense($item, $household, $wallet);

            // Completed items reject ordinary field edits until uncompleted.
            $this->actingAs($owner)
                ->patchJson("/api/lists/{$list->id}/items/{$item->id}", [
                    'title' => 'Updated Title',
                ])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'LIST_ITEM_ALREADY_COMPLETED');

            // The price is locked because the Expense exists.
            $this->actingAs($owner)
                ->patchJson("/api/lists/{$list->id}/items/{$item->id}", [
                    'actual_price' => '200.00',
                ])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'LIST_ITEM_PRICE_LOCKED');

            expect($item->refresh()->title)->not->toBe('Updated Title');
            expect($item->actual_price)->toBe('100.00');
        });

        it('item dengan Expense tetap bisa diuncomplete', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $wallet = legacyListsWallet($household);
            $list = legacyListsList($household);
            $item = legacyListsItem($list, [
                'completed_at' => now(),
                'actual_price' => '100.00',
            ]);
            $expense = legacyListsExpense($item, $household, $wallet);

            $this->actingAs($owner)
                ->postJson("/api/lists/{$list->id}/items/{$item->id}/uncomplete")
                ->assertStatus(200)
                ->assertJsonPath('data.status', 'active')
                ->assertJsonPath('data.actual_price', '100.00')
                ->assertJsonPath('data.expense.id', $expense->id);

            expect($item->refresh()->completed_at)->toBeNull();
            expect(Transaction::find($expense->id))->not->toBeNull();
        });
    });

    // -----------------------------------------------------------------
    // 8. Validasi unit opsional maksimal 20 karakter
    // -----------------------------------------------------------------

    describe('unit validation', function () {
        it('unit opsional diterima jika diisi < 20 karakter', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $list = legacyListsList($household);

            $this->actingAs($owner)
                ->postJson("/api/lists/{$list->id}/items", [
                    'title' => 'Test Item',
                    'quantity' => '2.00',
                    'unit' => 'kg',
                    'estimated_price' => '50.00',
                ])
                ->assertStatus(201)
                ->assertJsonPath('data.unit', 'kg');
        });

        it('unit ditolak jika > 20 karakter (validasi FormRequest)', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $list = legacyListsList($household);

            $this->actingAs($owner)
                ->postJson("/api/lists/{$list->id}/items", [
                    'title' => 'Test Item',
                    'quantity' => '2.00',
                    'unit' => 'this_is_a_very_long_unit_name_that_exceeds_twenty_chars',
                    'estimated_price' => '50.00',
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('unit');

            expect(ListItem::count())->toBe(0);
        });

        it('unit kosong (null) diterima', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $list = legacyListsList($household);

            $this->actingAs($owner)
                ->postJson("/api/lists/{$list->id}/items", [
                    'title' => 'Test Item',
                    'quantity' => '2.00',
                    'unit' => null,
                    'estimated_price' => '50.00',
                ])
                ->assertStatus(201)
                ->assertJsonPath('data.unit', null);
        });
    });

    // -----------------------------------------------------------------
    // 9. Kode error dan HTTP status sesuai kontrak API
    // -----------------------------------------------------------------

    describe('error codes and HTTP status', function () {
        it('semua error code sesuai kontrak LIST', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $wallet = legacyListsWallet($household);
            $default = legacyListsList($household);
            legacyListsSetDefault($household, $default->id);

            // DEFAULT_LIST_REQUIRED
            $this->actingAs($owner)
                ->postJson("/api/lists/{$default->id}/archive")
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'DEFAULT_LIST_REQUIRED');

            // LIST_ARCHIVED
            $archived = legacyListsList($household, ['type' => 'custom', 'archived_at' => now()]);
            $this->actingAs($owner)
                ->patchJson("/api/lists/{$archived->id}", ['name' => 'Nope'])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'LIST_ARCHIVED');

            // LIST_ITEM_NOT_COMPLETED (uncomplete)
            $active = legacyListsItem($default);
            $this->actingAs($owner)
                ->postJson("/api/lists/{$default->id}/items/{$active->id}/uncomplete")
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'LIST_ITEM_NOT_COMPLETED');

            // LIST_ITEM_ALREADY_COMPLETED
            $completed = legacyListsItem($default, ['completed_at' => now()]);
            $this->actingAs($owner)
                ->postJson("/api/lists/{$default->id}/items/{$completed->id}/complete")
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'LIST_ITEM_ALREADY_COMPLETED');

            // LIST_ITEM_ACTUAL_PRICE_REQUIRED
            $this->actingAs($owner)
                ->postJson("/api/lists/{$default->id}/items/{$completed->id}/expense", [
                    'wallet_id' => $wallet->id,
                ])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'LIST_ITEM_ACTUAL_PRICE_REQUIRED');

            // LIST_ITEM_PRICE_LOCKED
            $priced = legacyListsItem($default, [
                'completed_at' => now(),
                'actual_price' => '100.00',
            ]);
            legacyListsExpense($priced, $household, $wallet);
            $this->actingAs($owner)
                ->patchJson("/api/lists/{$default->id}/items/{$priced->id}", [
                    'actual_price' => '200.00',
                ])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'LIST_ITEM_PRICE_LOCKED');

            // LIST_ITEM_HAS_EXPENSE
            $this->actingAs($owner)
                ->deleteJson("/api/lists/{$default->id}/items/{$priced->id}")
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'LIST_ITEM_HAS_EXPENSE');

            // LIST_NOT_FOUND and LIST_ITEM_NOT_FOUND via cross-household mask
            $intruder = User::factory()->create();
            legacyListsHousehold($intruder, 'owner', 'Rumah Lain');

            $this->actingAs($intruder)
                ->getJson("/api/lists/{$default->id}")
                ->assertStatus(404)
                ->assertJsonPath('error.code', 'LIST_NOT_FOUND');

            $this->actingAs($intruder)
                ->patchJson("/api/lists/{$default->id}/items/{$active->id}", [
                    'title' => 'Nope',
                ])
                ->assertStatus(404)
                ->assertJsonPath('error.code', 'LIST_ITEM_NOT_FOUND');
        });

        it('HTTP status code correct for LIST error conditions', function () {
            $owner = User::factory()->create();
            $household = legacyListsHousehold($owner);
            $wallet = legacyListsWallet($household);

            // 422 — missing required type on list creation.
            $this->actingAs($owner)
                ->postJson('/api/lists', ['name' => 'Tanpa Tipe'])
                ->assertStatus(422)
                ->assertJsonValidationErrors('type');

            // 200 — archive is idempotent.
            $custom = legacyListsList($household, ['type' => 'custom']);
            $this->actingAs($owner)
                ->postJson("/api/lists/{$custom->id}/archive")
                ->assertStatus(200);
            $this->actingAs($owner)
                ->postJson("/api/lists/{$custom->id}/archive")
                ->assertStatus(200);

            // 409 — archived list rejects writes.
            $this->actingAs($owner)
                ->patchJson("/api/lists/{$custom->id}", ['name' => 'Nope'])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'LIST_ARCHIVED');

            // 200 — restore returns the row to the active state.
            $this->actingAs($owner)
                ->postJson("/api/lists/{$custom->id}/restore")
                ->assertStatus(200)
                ->assertJsonPath('data.archived_at', null);

            // 409 — default shopping list cannot be archived without a candidate.
            $default = legacyListsList($household);
            legacyListsSetDefault($household, $default->id);
            $this->actingAs($owner)
                ->postJson("/api/lists/{$default->id}/archive")
                ->assertStatus(409);

            // 422 — Expense without a usable actual price.
            $item = legacyListsItem($default, ['completed_at' => now()]);
            $this->actingAs($owner)
                ->postJson("/api/lists/{$default->id}/items/{$item->id}/expense", [
                    'wallet_id' => $wallet->id,
                ])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'LIST_ITEM_ACTUAL_PRICE_REQUIRED');

            // 404 — cross-household access is masked.
            $intruder = User::factory()->create();
            legacyListsHousehold($intruder, 'owner', 'Rumah Lain');
            $this->actingAs($intruder)
                ->getJson("/api/lists/{$default->id}")
                ->assertStatus(404)
                ->assertJsonPath('error.code', 'LIST_NOT_FOUND');
        });
    });
});
