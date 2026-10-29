<?php

use App\Models\Household;
use App\Models\HouseholdList;
use App\Models\HouseholdMember;
use App\Models\ListItem;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;

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
            ->assertJsonPath('data.is_completed', true)
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
            ->assertJsonPath('data.is_completed', false)
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

describe('item reads, deletes, and nested isolation', function () {
    test('GET nested item returns the contract presenter', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $list = listsList($household);
        $item = listsItem($list, ['title' => 'Beras']);

        $this->actingAs($owner)
            ->getJson("/api/lists/{$list->id}/items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $item->id)
            ->assertJsonPath('data.title', 'Beras')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.expense', null);
    });

    test('an item from another list in the same household is masked as not found', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $first = listsList($household, ['name' => 'Pertama']);
        $second = listsList($household, ['name' => 'Kedua']);
        $foreignItem = listsItem($second);

        $this->actingAs($owner)
            ->getJson("/api/lists/{$first->id}/items/{$foreignItem->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'LIST_ITEM_NOT_FOUND');
    });

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

describe('Expense endpoint duplicate prevention and authorization', function () {
    test('owner creates one Expense and retries return the same row', function () {
        $owner = User::factory()->create();
        $household = listsHousehold($owner);
        $wallet = listsWallet($household);
        $list = listsList($household);
        $item = listsItem($list, ['completed_at' => now(), 'actual_price' => '100.00']);

        $first = $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                'wallet_id' => $wallet->id,
                'actual_price' => '100.00',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.amount', '100.00');

        $this->actingAs($owner)
            ->postJson("/api/lists/{$list->id}/items/{$item->id}/expense", [
                'wallet_id' => $wallet->id,
                'actual_price' => '100.00',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        expect(Transaction::count())->toBe(1);
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
});
