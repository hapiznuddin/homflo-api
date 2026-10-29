<?php

use App\Models\Category;
use App\Models\Household;
use App\Models\HouseholdList;
use App\Models\HouseholdMember;
use App\Models\ListItem;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\PeriodBounds;

/**
 * SQLite runtime coverage for the Lists API fixes in Phase 16.6.9.
 *
 * The legacy PostgreSQL-gated ListsTest remains skipped when PostgreSQL
 * is unavailable; this file provides executable coverage of the same
 * contract areas on the suite's configured SQLite connection.
 */
function listsHousehold(User $owner, string $role = 'owner', string $name = 'Rumah Lists'): Household
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

/**
 * @param  array<string, mixed>  $overrides
 */
function listsList(Household $household, array $overrides = []): HouseholdList
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
function listsItem(HouseholdList $list, array $overrides = []): ListItem
{
    return ListItem::create(array_merge([
        'list_id' => $list->id,
        'title' => 'Sabun Cuci',
        'quantity' => null,
        'unit' => null,
        'estimated_price' => null,
        'actual_price' => null,
        'completed_at' => null,
        'completed_by' => null,
        'created_by' => $list->created_by,
    ], $overrides));
}

function listsExpense(ListItem $item, Household $household, Wallet $wallet): Transaction
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

function listsSetDefault(Household $household, ?string $listId): void
{
    $household->default_list_id = $listId;
    $household->save();
    $household->refresh();
}

function listsWallet(Household $household, string $openingBalance = '100000.00'): Wallet
{
    return Wallet::create([
        'household_id' => $household->id,
        'name' => 'Tunai',
        'type' => Wallet::TYPE_CASH,
        'opening_balance' => $openingBalance,
        'is_active' => true,
    ]);
}

describe('POST /api/lists type validation', function () {
    test('type is required', function () {
        $owner = User::factory()->create();
        listsHousehold($owner);

        $this->actingAs($owner)
            ->postJson('/api/lists', ['name' => 'Tanpa Tipe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    });

    test('type only accepts shopping or custom', function () {
        $owner = User::factory()->create();
        listsHousehold($owner);

        $this->actingAs($owner)
            ->postJson('/api/lists', ['name' => 'Tipe Salah', 'type' => 'wishlist'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    });

    test('valid shopping list becomes default when pointer is empty', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);

        $response = $this->actingAs($owner)
            ->postJson('/api/lists', ['name' => 'Belanja', 'type' => 'shopping'])
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'shopping')
            ->assertJsonPath('data.is_default', true);

        expect($household->refresh()->default_list_id)->toBe($response->json('data.id'));
    });

    test('valid custom list does not claim the default pointer', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);

        $response = $this->actingAs($owner)
            ->postJson('/api/lists', ['name' => 'Ide Renovasi', 'type' => 'custom'])
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'custom')
            ->assertJsonPath('data.is_default', false);

        expect($household->refresh()->default_list_id)->toBeNull();
        expect($response->json('data.id'))->toBeString();
    });

    test('a second shopping list does not overwrite the existing default', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $first = listsList($household);
        listsSetDefault($household, $first->id);

        $this->actingAs($owner)
            ->postJson('/api/lists', ['name' => 'Belanja Kedua', 'type' => 'shopping'])
            ->assertStatus(201)
            ->assertJsonPath('data.is_default', false);

        expect($household->refresh()->default_list_id)->toBe($first->id);
    });
});

describe('GET /api/lists filters', function () {
    test('archived lists stay hidden unless requested and type is filterable', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $shopping = listsList($household, ['name' => 'Belanja']);
        listsList($household, ['name' => 'Custom', 'type' => 'custom']);
        listsList($household, ['name' => 'Arsip', 'archived_at' => now()]);

        $this->actingAs($owner)
            ->getJson('/api/lists')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($owner)
            ->getJson('/api/lists?type=custom')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Custom');

        $this->actingAs($owner)
            ->getJson('/api/lists?include_archived=true')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        expect($shopping->id)->toBeString();
    });

    test('invalid list filters return 422', function () {
        $owner = User::factory()->create();
        listsHousehold($owner);

        $this->actingAs($owner)
            ->getJson('/api/lists?type=wishlist')
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');

        $this->actingAs($owner)
            ->getJson('/api/lists?include_archived=maybe')
            ->assertStatus(422)
            ->assertJsonValidationErrors('include_archived');
    });
});

describe('complete and uncomplete', function () {
    test('complete derives status, stamps attribution, and never creates an Expense', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $list = listsList($household);
        $item = listsItem($list);

        $response = $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/complete", [
                'actual_price' => '12500.00',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.actual_price', '12500.00')
            ->assertJsonPath('data.completed_by.id', $owner->id)
            ->assertJsonPath('data.expense', null);

        expect($response->json('data.completed_at'))->not->toBeNull();
        expect(Transaction::count())->toBe(0);
    });

    test('uncomplete clears completion fields and retains actual_price', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $list = listsList($household);
        $item = listsItem($list, [
            'completed_at' => now(),
            'completed_by' => $owner->id,
            'actual_price' => '150.00',
        ]);

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/uncomplete")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.completed_by', null)
            ->assertJsonPath('data.actual_price', '150.00');
    });

    test('re-completion without actual_price preserves the snapshot', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $list = listsList($household);
        $item = listsItem($list, ['actual_price' => '99.00']);

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.actual_price', '99.00');
    });

    test('completing an already completed item returns 409', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $list = listsList($household);
        $item = listsItem($list, ['completed_at' => now()]);

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/complete")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LIST_ITEM_ALREADY_COMPLETED');
    });

    test('uncompleting an active item returns 409', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $list = listsList($household);
        $item = listsItem($list);

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/uncomplete")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LIST_ITEM_NOT_COMPLETED');
    });

    test('archived lists reject complete and uncomplete', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $list = listsList($household, ['archived_at' => now()]);
        $active = listsItem($list);
        $completed = listsItem($list, ['completed_at' => now()]);

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$active->id}/complete")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LIST_ARCHIVED');

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$completed->id}/uncomplete")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LIST_ARCHIVED');
    });

    test('cross-household completion is masked as item not found', function () {
        $owner = User::factory()->create();
        listsHousehold($owner);

        $stranger = User::factory()->create();
        $foreignHousehold = listsHousehold($stranger, 'owner', 'Rumah Asing');
        $foreignList = listsList($foreignHousehold);
        $foreignItem = listsItem($foreignList);

        $this->actingAs($owner)
            ->postJson("/api/lists/{$foreignList->id}/items/{$foreignItem->id}/complete")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'LIST_ITEM_NOT_FOUND');

        expect($foreignItem->refresh()->completed_at)->toBeNull();
    });

    test('a same-household member may complete items', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $member = User::factory()->create();
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
        $list = listsList($household);
        $item = listsItem($list);

        $this->actingAs($member)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.completed_by.id', $member->id);
    });

    test('an existing Expense blocks changed actual_price and is never duplicated', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $wallet = listsWallet($household);
        $list = listsList($household);
        $item = listsItem($list, [
            'completed_at' => now(),
            'completed_by' => $owner->id,
            'actual_price' => '100.00',
        ]);
        $expense = listsExpense($item, $household, $wallet);

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/uncomplete")
            ->assertOk()
            ->assertJsonPath('data.actual_price', '100.00');

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/complete", [
                'actual_price' => '200.00',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LIST_ITEM_PRICE_LOCKED');

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.actual_price', '100.00');

        expect($item->refresh()->actual_price)->toBe('100.00');
        expect(Transaction::whereKey($expense->id)->count())->toBe(1);
        expect(Transaction::count())->toBe(1);
    });
});

describe('GET /api/lists/{list}/items response shape', function () {
    test('an empty list returns the grouped contract shape', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $list = listsList($household);

        $this->actingAs($owner)
            ->getJson("/api/lists/{$list->id}/items")
            ->assertOk()
            ->assertJsonPath('data.list_id', $list->id)
            ->assertJsonPath('data.counts.active', 0)
            ->assertJsonPath('data.counts.completed', 0)
            ->assertJsonCount(0, 'data.active')
            ->assertJsonCount(0, 'data.completed');
    });

    test('items are grouped with independent contract ordering and presenter shape', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $list = listsList($household);

        $activeOld = listsItem($list, ['title' => 'Aktif Lama']);
        $activeOld->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $activeNew = listsItem($list, ['title' => 'Aktif Baru']);
        $activeNew->forceFill(['created_at' => now()->subMinute()])->save();

        $completedOld = listsItem($list, [
            'title' => 'Selesai Lama',
            'completed_at' => now()->subMinutes(10),
        ]);
        $completedNew = listsItem($list, [
            'title' => 'Selesai Baru',
            'completed_at' => now()->subMinute(),
        ]);

        $this->actingAs($owner)
            ->getJson("/api/lists/{$list->id}/items")
            ->assertOk()
            ->assertJsonPath('data.list_id', $list->id)
            ->assertJsonPath('data.counts.active', 2)
            ->assertJsonPath('data.counts.completed', 2)
            ->assertJsonPath('data.active.0.title', 'Aktif Lama')
            ->assertJsonPath('data.active.1.title', 'Aktif Baru')
            ->assertJsonPath('data.active.0.status', 'active')
            ->assertJsonPath('data.completed.0.title', 'Selesai Baru')
            ->assertJsonPath('data.completed.1.title', 'Selesai Lama')
            ->assertJsonPath('data.completed.0.status', 'completed')
            ->assertJsonPath('data.active.0.expense', null)
            ->assertJsonStructure([
                'data' => [
                    'list_id',
                    'counts' => ['active', 'completed'],
                    'active' => [[
                        'id',
                        'list_id',
                        'title',
                        'quantity',
                        'unit',
                        'estimated_price',
                        'actual_price',
                        'status',
                        'completed_at',
                        'completed_by',
                        'created_by',
                        'expense',
                        'created_at',
                        'updated_at',
                    ]],
                    'completed',
                ],
            ])
            ->assertJsonMissingPath('data.active.0.is_completed');

        expect($completedOld->id)->toBeString();
        expect($completedNew->id)->toBeString();
    });

    test('the non-MVP nested item route is no longer registered', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $list = listsList($household);
        $item = listsItem($list);

        // PATCH/DELETE own /items/{item}; a GET is not routed (405), which
        // proves the read endpoint from §10.8 was removed rather than
        // shadowed by a new controller action.
        $this->actingAs($owner)
            ->getJson("/api/lists/{$list->id}/items/{$item->id}")
            ->assertStatus(405);
    });
});

describe('item deletion', function () {
    test('deleting an item without Expense hard-deletes it', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $list = listsList($household);
        $item = listsItem($list);

        $this->actingAs($owner)
            ->deleteJson("/api/lists/{$list->id}/items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $item->id);

        expect(ListItem::find($item->id))->toBeNull();
    });

    test('deleting an item with Expense returns 409 and keeps the ledger row', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $wallet = listsWallet($household);
        $list = listsList($household);
        $item = listsItem($list, ['completed_at' => now(), 'actual_price' => '100.00']);
        $expense = listsExpense($item, $household, $wallet);

        $this->actingAs($owner)
            ->deleteJson("/api/lists/{$list->id}/items/{$item->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LIST_ITEM_HAS_EXPENSE');

        expect(ListItem::find($item->id))->not->toBeNull();
        expect(Transaction::find($expense->id))->not->toBeNull();
    });
});

describe('list archive and restore lifecycle', function () {
    test('archiving a non-default list archives it', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $default = listsList($household);
        listsSetDefault($household, $default->id);
        $target = listsList($household, ['name' => 'Arsip Saya']);

        $this->actingAs($owner)
            ->postJson("/api/lists/{$target->id}/archive")
            ->assertOk();

        expect($target->refresh()->archived_at)->not->toBeNull();
        expect($household->refresh()->default_list_id)->toBe($default->id);
    });

    test('archiving the default moves the pointer to the oldest active shopping list', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $default = listsList($household, ['name' => 'Lama']);
        $candidate = listsList($household, ['name' => 'Baru']);
        listsSetDefault($household, $default->id);

        $this->actingAs($owner)
            ->postJson("/api/lists/{$default->id}/archive")
            ->assertOk();

        expect($household->refresh()->default_list_id)->toBe($candidate->id);
        expect($default->refresh()->archived_at)->not->toBeNull();
    });

    test('archiving the last default shopping list returns 409', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $default = listsList($household);
        listsSetDefault($household, $default->id);

        $this->actingAs($owner)
            ->postJson("/api/lists/{$default->id}/archive")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DEFAULT_LIST_REQUIRED');

        expect($default->refresh()->archived_at)->toBeNull();
    });

    test('archive is idempotent and restore clears archived_at', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $list = listsList($household, ['archived_at' => now()]);

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/archive")
            ->assertOk();

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.archived_at', null);
    });
});

describe('Expense endpoint contract', function () {
    test('created response follows the contract envelope and derives every field', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $wallet = listsWallet($household);
        $category = Category::factory()->create([
            'household_id' => $household->id,
            'type' => Category::TYPE_EXPENSE,
        ]);
        $list = listsList($household);
        $item = listsItem($list, [
            'title' => 'Sabun Cuci',
            'completed_at' => now(),
            'actual_price' => '12500.00',
        ]);

        $response = $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                'wallet_id' => $wallet->id,
                'category_id' => $category->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.created', true)
            ->assertJsonPath('data.expense.type', Transaction::TYPE_EXPENSE)
            ->assertJsonPath('data.expense.amount', '12500.00')
            ->assertJsonPath('data.expense.description', 'Sabun Cuci')
            ->assertJsonPath('data.expense.wallet_id', $wallet->id)
            ->assertJsonPath('data.expense.category.id', $category->id)
            ->assertJsonPath('data.expense.occurred_on', PeriodBounds::businessToday())
            ->assertJsonPath('data.item.id', $item->id);

        $expenseId = $response->json('data.expense.id');
        $stored = Transaction::find($expenseId);

        expect($stored)->not->toBeNull();
        expect($stored->source_type)->toBe(ListItem::class);
        expect($stored->source_id)->toBe($item->id);
        expect($response->json('data.item.expense.id'))->toBe($expenseId);
        expect($response->json('data.item.expense.amount'))->toBe('12500.00');
        expect(Transaction::count())->toBe(1);
    });

    test('a retry returns created=false and the same Expense', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $wallet = listsWallet($household);
        $list = listsList($household);
        $item = listsItem($list, ['completed_at' => now(), 'actual_price' => '100.00']);

        $first = $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                'wallet_id' => $wallet->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.created', true);

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                'wallet_id' => $wallet->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.expense.id', $first->json('data.expense.id'))
            ->assertJsonPath('data.item.expense.id', $first->json('data.expense.id'));

        expect(Transaction::count())->toBe(1);
    });

    test('amount falls back to the stored actual_price when the payload omits it', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $wallet = listsWallet($household);
        $list = listsList($household);
        $item = listsItem($list, ['completed_at' => now(), 'actual_price' => '250.50']);

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                'wallet_id' => $wallet->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.expense.amount', '250.50')
            ->assertJsonPath('data.item.actual_price', '250.50');
    });

    test('an actual_price override is applied atomically to both item and ledger', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $wallet = listsWallet($household);
        $list = listsList($household);
        $item = listsItem($list, ['completed_at' => now(), 'actual_price' => '100.00']);

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                'wallet_id' => $wallet->id,
                'actual_price' => '175.25',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.expense.amount', '175.25')
            ->assertJsonPath('data.item.actual_price', '175.25');

        expect($item->refresh()->actual_price)->toBe('175.25');
    });

    test('a null or non-positive final price returns 422 without writing', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $wallet = listsWallet($household);
        $list = listsList($household);
        $item = listsItem($list, ['completed_at' => now(), 'actual_price' => null]);

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                'wallet_id' => $wallet->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'LIST_ITEM_ACTUAL_PRICE_REQUIRED');

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

    test('wallet_id is required and must be an active same-household wallet', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $wallet = listsWallet($household);
        $inactive = Wallet::factory()->inactive()->create(['household_id' => $household->id]);
        $foreignHousehold = listsHousehold(User::factory()->create(), 'owner', 'Rumah Lain');
        $foreignWallet = listsWallet($foreignHousehold);
        $list = listsList($household);
        $item = listsItem($list, ['completed_at' => now(), 'actual_price' => '100.00']);

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('wallet_id');

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                'wallet_id' => $inactive->id,
            ])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'WALLET_NOT_FOUND');

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                'wallet_id' => $foreignWallet->id,
            ])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'WALLET_NOT_FOUND');

        expect($wallet->id)->toBeString();
        expect(Transaction::count())->toBe(0);
    });

    test('category must be an expense category in the same household', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $wallet = listsWallet($household);
        $incomeCategory = Category::factory()->create([
            'household_id' => $household->id,
            'type' => Category::TYPE_INCOME,
        ]);
        $foreignCategory = Category::factory()->create([
            'household_id' => listsHousehold(User::factory()->create(), 'owner', 'Rumah Lain')->id,
            'type' => Category::TYPE_EXPENSE,
        ]);
        $list = listsList($household);
        $item = listsItem($list, ['completed_at' => now(), 'actual_price' => '100.00']);

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                'wallet_id' => $wallet->id,
                'category_id' => $incomeCategory->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CATEGORY_TYPE_MISMATCH');

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                'wallet_id' => $wallet->id,
                'category_id' => $foreignCategory->id,
            ])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'CATEGORY_NOT_FOUND');

        expect(Transaction::count())->toBe(0);
    });

    test('insufficient funds rolls back every write', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $wallet = listsWallet($household, '50.00');
        $list = listsList($household);
        $item = listsItem($list, ['completed_at' => now(), 'actual_price' => '100.00']);

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                'wallet_id' => $wallet->id,
                'actual_price' => '150.00',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INSUFFICIENT_FUNDS');

        expect(Transaction::count())->toBe(0);
        expect($item->refresh()->actual_price)->toBe('100.00');
    });

    test('an item must be completed before Expense creation', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $wallet = listsWallet($household);
        $list = listsList($household);
        $item = listsItem($list, ['actual_price' => '100.00']);

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                'wallet_id' => $wallet->id,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LIST_ITEM_NOT_COMPLETED');

        expect(Transaction::count())->toBe(0);
    });

    test('same-household member cannot create Expense', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $wallet = listsWallet($household);
        $member = User::factory()->create();
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
        $list = listsList($household);
        $item = listsItem($list, ['completed_at' => now(), 'actual_price' => '100.00']);

        $this->actingAs($member)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                'wallet_id' => $wallet->id,
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        expect(Transaction::count())->toBe(0);
    });

    test('archived list rejects Expense creation', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $wallet = listsWallet($household);
        $list = listsList($household, ['archived_at' => now()]);
        $item = listsItem($list, ['completed_at' => now(), 'actual_price' => '100.00']);

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                'wallet_id' => $wallet->id,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LIST_ARCHIVED');

        expect(Transaction::count())->toBe(0);
    });

    test('uncomplete keeps the Expense and re-completion never duplicates it', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $wallet = listsWallet($household);
        $list = listsList($household);
        $item = listsItem($list, ['completed_at' => now(), 'actual_price' => '100.00']);

        $expenseId = $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                'wallet_id' => $wallet->id,
            ])
            ->assertStatus(201)
            ->json('data.expense.id');

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/uncomplete")
            ->assertOk()
            ->assertJsonPath('data.actual_price', '100.00')
            ->assertJsonPath('data.expense.id', $expenseId);

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.expense.id', $expenseId);

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                'wallet_id' => $wallet->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.expense.id', $expenseId);

        expect(Transaction::count())->toBe(1);
    });

    test('list item Expenses are locked from generic Money edit and delete', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $wallet = listsWallet($household);
        $list = listsList($household);
        $item = listsItem($list, ['completed_at' => now(), 'actual_price' => '100.00']);

        $expenseId = $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                'wallet_id' => $wallet->id,
            ])
            ->assertStatus(201)
            ->json('data.expense.id');

        $this->actingAs($owner)
            ->patchJson("/api/transactions/{$expenseId}", ['amount' => '50.00'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'LIST_EXPENSE_LOCKED');

        $this->actingAs($owner)
            ->deleteJson("/api/transactions/{$expenseId}")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'LIST_EXPENSE_LOCKED');

        expect(Transaction::find($expenseId))->not->toBeNull();
        expect($item->refresh()->actual_price)->toBe('100.00');
    });
});
