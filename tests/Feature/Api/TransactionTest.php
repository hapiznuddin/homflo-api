<?php

use App\Models\Bill;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Policies\TransactionPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function txHousehold(User $owner, string $name = 'Rumah Transaksi', string $role = 'owner'): Household
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

function txRecord(Household $household, array $overrides = []): Transaction
{
    return Transaction::factory()
        ->for($household)
        ->create($overrides);
}

/**
 * Funded wallet for posting tests. Write paths lock the wallet and
 * validate the resulting balance, so posting tests need real funds.
 */
function txWallet(Household $household, array $overrides = []): Wallet
{
    return Wallet::factory()
        ->for($household)
        ->create(array_merge([
            'opening_balance' => '100000000.00',
        ], $overrides));
}

function txPayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'expense',
        'amount' => 150000,
        'occurred_on' => '2026-10-05',
        'description' => 'Belanja dapur',
        'wallet' => 'Kas Tunai',
    ], $overrides);
}

describe('Transactions API authentication', function () {
    test('guest cannot access protected transaction endpoints', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $transaction = txRecord($household);

        $this->getJson('/api/transactions')->assertStatus(401);
        $this->postJson('/api/transactions', txPayload())->assertStatus(401);
        $this->getJson("/api/transactions/{$transaction->id}")->assertStatus(401);
        $this->patchJson("/api/transactions/{$transaction->id}", ['description' => 'x'])->assertStatus(401);
        $this->deleteJson("/api/transactions/{$transaction->id}")->assertStatus(401);
    });

    test('user without household receives household not found', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/transactions')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');

        $this->actingAs($user)->postJson('/api/transactions', txPayload(['wallet_id' => (string) Str::ulid()]))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');
    });
});

describe('Transactions API authorization and household isolation', function () {
    test('member lists only their own household transactions', function () {
        $owner = User::factory()->create();
        $mine = txHousehold($owner, 'Milikku');
        txRecord($mine, ['description' => 'Susu UHT']);
        txRecord($mine, ['description' => 'Telur ayam']);

        $neighbor = User::factory()->create();
        $theirs = txHousehold($neighbor, 'Tetangga');
        txRecord($theirs, ['description' => 'Rahasia tetangga']);

        $response = $this->actingAs($owner)->getJson('/api/transactions');

        $response->assertOk()->assertJsonPath('success', true);
        $response->assertJsonPath('data.meta.total', 2);
        expect($response->json('data.data'))->toHaveCount(2);
        expect($response->getContent())->not->toContain('Rahasia tetangga');
    });

    test('cross-household detail access never leaks existence', function () {
        $owner = User::factory()->create();
        txHousehold($owner);

        $stranger = User::factory()->create();
        $foreign = txHousehold($stranger, 'Asing');
        $transaction = txRecord($foreign);

        $this->actingAs($owner)->getJson("/api/transactions/{$transaction->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'TRANSACTION_NOT_FOUND');

        $this->actingAs($owner)->patchJson("/api/transactions/{$transaction->id}", ['description' => 'bajak'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'TRANSACTION_NOT_FOUND');

        $this->actingAs($owner)->deleteJson("/api/transactions/{$transaction->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'TRANSACTION_NOT_FOUND');

        expect(Transaction::find($transaction->id))->not->toBeNull();
        expect(Transaction::find($transaction->id)->description)->not->toBe('bajak');
    });

    test('forged household ownership in payload is ignored', function () {
        $owner = User::factory()->create();
        $mine = txHousehold($owner);
        $wallet = txWallet($mine);

        $stranger = User::factory()->create();
        $foreign = txHousehold($stranger, 'Asing');

        $response = $this->actingAs($owner)->postJson('/api/transactions', txPayload([
            'household_id' => $foreign->id,
            'user_id' => $stranger->id,
            'role' => 'owner',
            'wallet_id' => $wallet->id,
        ]));

        $response->assertStatus(201);
        $response->assertJsonPath('data.household_id', $mine->id);
        expect(Transaction::where('household_id', $foreign->id)->count())->toBe(0);

        $transaction = txRecord($mine);

        $update = $this->actingAs($owner)->patchJson("/api/transactions/{$transaction->id}", [
            'description' => 'diubah',
            'household_id' => $foreign->id,
        ]);

        $update->assertOk();
        expect($transaction->refresh()->household_id)->toBe($mine->id);
        expect($update->json('data'))->not->toHaveKeys(['user_id', 'role']);
    });

    test('foreign and missing categories surface identically as not found', function () {
        $owner = User::factory()->create();
        $mine = txHousehold($owner);
        $wallet = txWallet($mine);

        $stranger = User::factory()->create();
        $foreignHousehold = txHousehold($stranger, 'Asing');
        $foreignCategory = Category::factory()->for($foreignHousehold)->create();

        $foreignResponse = $this->actingAs($owner)->postJson('/api/transactions', txPayload([
            'category_id' => $foreignCategory->id,
            'wallet_id' => $wallet->id,
        ]));

        $foreignResponse
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'CATEGORY_NOT_FOUND');

        $missingResponse = $this->actingAs($owner)->postJson('/api/transactions', txPayload([
            'category_id' => (string) Str::ulid(),
            'wallet_id' => $wallet->id,
        ]));

        $missingResponse
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'CATEGORY_NOT_FOUND');

        expect($missingResponse->json('error'))->toEqual($foreignResponse->json('error'));

        $transaction = txRecord($mine);

        $this->actingAs($owner)->patchJson("/api/transactions/{$transaction->id}", [
            'category_id' => $foreignCategory->id,
        ])->assertStatus(404)->assertJsonPath('error.code', 'CATEGORY_NOT_FOUND');
    });

    test('unverified member can read but cannot mutate the ledger', function () {
        $owner = User::factory()->unverified()->create();
        $household = txHousehold($owner);
        $transaction = txRecord($household);

        $this->actingAs($owner)->getJson('/api/transactions')->assertOk();
        $this->actingAs($owner)->getJson("/api/transactions/{$transaction->id}")->assertOk();

        $this->actingAs($owner)->postJson('/api/transactions', txPayload())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->actingAs($owner)->patchJson("/api/transactions/{$transaction->id}", ['description' => 'x'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->actingAs($owner)->deleteJson("/api/transactions/{$transaction->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
    });

    test('member role (non-owner) reads but cannot mutate the shared ledger', function () {
        $member = User::factory()->create();
        $household = txHousehold($member, 'Keluarga', 'member');
        $wallet = txWallet($household);
        $transaction = txRecord($household);

        $this->actingAs($member)->getJson('/api/transactions')->assertOk();
        $this->actingAs($member)->getJson("/api/transactions/{$transaction->id}")->assertOk();

        $this->actingAs($member)->postJson('/api/transactions', txPayload(['wallet_id' => $wallet->id]))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');

        $this->actingAs($member)->patchJson("/api/transactions/{$transaction->id}", ['description' => 'oleh member'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'TRANSACTION_NOT_FOUND');

        $this->actingAs($member)->deleteJson("/api/transactions/{$transaction->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'TRANSACTION_NOT_FOUND');

        expect(Transaction::where('household_id', $household->id)->count())->toBe(1);
    });
});

describe('Transactions API validation and persistence', function () {
    test('store validates required fields', function () {
        $owner = User::factory()->create();
        txHousehold($owner);

        $this->actingAs($owner)->postJson('/api/transactions', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type', 'wallet_id', 'amount', 'occurred_on']);
    });

    test('store rejects malformed input', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $wallet = txWallet($household);

        $withWallet = fn (array $overrides): array => txPayload(array_merge(['wallet_id' => $wallet->id], $overrides));

        $this->actingAs($owner)->postJson('/api/transactions', $withWallet(['type' => 'profit']))
            ->assertStatus(422)->assertJsonValidationErrors(['type']);

        $this->actingAs($owner)->postJson('/api/transactions', $withWallet(['category_id' => 'bukan-ulid']))
            ->assertStatus(422)->assertJsonValidationErrors(['category_id']);

        $this->actingAs($owner)->postJson('/api/transactions', $withWallet(['amount' => 'banyak']))
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->actingAs($owner)->postJson('/api/transactions', $withWallet(['amount' => -100]))
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->actingAs($owner)->postJson('/api/transactions', $withWallet(['amount' => 10000000000000]))
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->actingAs($owner)->postJson('/api/transactions', $withWallet(['occurred_on' => '05-10-2026']))
            ->assertStatus(422)->assertJsonValidationErrors(['occurred_on']);

        $this->actingAs($owner)->postJson('/api/transactions', $withWallet(['wallet' => str_repeat('w', 101)]))
            ->assertStatus(422)->assertJsonValidationErrors(['wallet']);
    });

    test('store rejects zero amounts for income, expense, and transfer', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $wallet = txWallet($household);
        $destination = txWallet($household, ['name' => 'Bank']);
        $incomeCategory = Category::factory()->for($household)->create(['name' => 'Gaji', 'type' => 'income']);
        $expenseCategory = Category::factory()->for($household)->create(['name' => 'Dapur', 'type' => 'expense']);

        foreach ([
            ['type' => 'income', 'category_id' => $incomeCategory->id],
            ['type' => 'expense', 'category_id' => $expenseCategory->id],
        ] as $case) {
            $this->actingAs($owner)->postJson('/api/transactions', txPayload(array_merge($case, [
                'amount' => 0,
                'wallet_id' => $wallet->id,
            ])))->assertStatus(422)->assertJsonValidationErrors(['amount']);
        }

        $this->actingAs($owner)->postJson('/api/transactions', txPayload([
            'type' => 'transfer',
            'amount' => '0.00',
            'wallet_id' => $wallet->id,
            'destination_wallet_id' => $destination->id,
        ]))->assertStatus(422)->assertJsonValidationErrors(['amount']);

        expect(Transaction::where('household_id', $household->id)->count())->toBe(0);
        expect($wallet->refresh()->opening_balance)->toBe('100000000.00');
    });

    test('update rejects zero amounts without touching balances', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $wallet = txWallet($household, ['opening_balance' => '100000.00']);
        $destination = txWallet($household, ['name' => 'Bank', 'opening_balance' => '0.00']);
        $incomeCategory = Category::factory()->for($household)->create(['name' => 'Gaji', 'type' => 'income']);
        $expenseCategory = Category::factory()->for($household)->create(['name' => 'Dapur', 'type' => 'expense']);

        $income = Transaction::factory()->for($household)->create([
            'type' => 'income',
            'category_id' => $incomeCategory->id,
            'wallet_id' => $wallet->id,
            'amount' => '60000.00',
            'occurred_on' => '2026-10-05',
        ]);
        $expense = Transaction::factory()->for($household)->create([
            'type' => 'expense',
            'category_id' => $expenseCategory->id,
            'wallet_id' => $wallet->id,
            'amount' => '20000.00',
            'occurred_on' => '2026-10-06',
        ]);
        $transfer = Transaction::factory()->for($household)->create([
            'type' => 'transfer',
            'category_id' => null,
            'wallet_id' => $wallet->id,
            'destination_wallet_id' => $destination->id,
            'amount' => '10000.00',
            'occurred_on' => '2026-10-07',
        ]);

        foreach ([$income->id, $expense->id, $transfer->id] as $id) {
            $this->actingAs($owner)->patchJson("/api/transactions/{$id}", ['amount' => 0])
                ->assertStatus(422)->assertJsonValidationErrors(['amount']);
        }

        expect($income->refresh()->amount)->toBe('60000.00');
        expect($expense->refresh()->amount)->toBe('20000.00');
        expect($transfer->refresh()->amount)->toBe('10000.00');

        $balance = $this->actingAs($owner)->getJson("/api/wallets/{$wallet->id}")->json('data.balance');
        expect($balance)->toBe('130000.00');
        expect(Transaction::where('household_id', $household->id)->count())->toBe(3);
    });

    test('historical zero-amount rows stay readable and untouched', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $wallet = txWallet($household, ['opening_balance' => '50000.00']);

        $legacy = Transaction::factory()->for($household)->create([
            'type' => 'expense',
            'category_id' => null,
            'wallet_id' => $wallet->id,
            'amount' => '0.00',
            'occurred_on' => '2026-10-05',
        ]);

        $this->actingAs($owner)->getJson("/api/transactions/{$legacy->id}")
            ->assertOk()
            ->assertJsonPath('data.amount', '0.00');

        $this->actingAs($owner)->patchJson("/api/transactions/{$legacy->id}", ['description' => 'koreksi teks'])
            ->assertOk();

        expect($legacy->refresh()->amount)->toBe('0.00');
        expect($legacy->description)->toBe('koreksi teks');

        $balance = $this->actingAs($owner)->getJson("/api/wallets/{$wallet->id}")->json('data.balance');
        expect($balance)->toBe('50000.00');
    });

    test('store persists exact values with embedded category', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $wallet = txWallet($household);
        $category = Category::factory()->for($household)->create(['name' => 'Dapur', 'icon' => 'i-lucide-egg']);

        $response = $this->actingAs($owner)->postJson('/api/transactions', txPayload([
            'type' => 'income',
            'amount' => '150000.50',
            'category_id' => $category->id,
            'wallet_id' => $wallet->id,
            'description' => null,
            'wallet' => null,
        ]));

        $response->assertStatus(201)->assertJsonPath('success', true);

        $data = $response->json('data');

        expect($data['type'])->toBe('income');
        expect($data['amount'])->toBe('150000.50');
        expect($data['occurred_on'])->toBe('2026-10-05');
        expect($data['household_id'])->toBe($household->id);
        expect($data['description'])->toBeNull();
        expect($data['wallet'])->toBeNull();
        expect($data['category'])->toEqual([
            'id' => $category->id,
            'name' => 'Dapur',
            'icon' => 'i-lucide-egg',
        ]);

        expect(Transaction::where('household_id', $household->id)->count())->toBe(1);
    });

    test('store normalizes integer amounts to exact decimals', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $wallet = txWallet($household);

        $response = $this->actingAs($owner)->postJson('/api/transactions', txPayload([
            'amount' => 150000,
            'wallet_id' => $wallet->id,
        ]));

        $response->assertStatus(201);
        expect($response->json('data.amount'))->toBe('150000.00');
    });

    test('update applies partial changes without touching ownership', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $category = Category::factory()->for($household)->create();
        $other = Category::factory()->for($household)->create(['name' => 'Transport']);
        $transaction = txRecord($household, ['category_id' => $category->id, 'type' => 'expense', 'occurred_on' => '2026-10-05']);

        $response = $this->actingAs($owner)->patchJson("/api/transactions/{$transaction->id}", [
            'amount' => '200000.75',
            'description' => 'revisi',
            'category_id' => $other->id,
        ]);

        $response->assertOk();

        $fresh = $transaction->refresh();

        expect((string) $fresh->amount)->toBe('200000.75');
        expect($fresh->description)->toBe('revisi');
        expect($fresh->category_id)->toBe($other->id);
        expect($fresh->type)->toBe('expense');
        expect($fresh->occurred_on->format('Y-m-d'))->toBe('2026-10-05');
        expect($fresh->household_id)->toBe($household->id);
    });

    test('update can detach the category', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $category = Category::factory()->for($household)->create();
        $transaction = txRecord($household, ['category_id' => $category->id]);

        $response = $this->actingAs($owner)->patchJson("/api/transactions/{$transaction->id}", [
            'category_id' => null,
        ]);

        $response->assertOk();
        expect($transaction->refresh()->category_id)->toBeNull();
        expect($response->json('data.category'))->toBeNull();
    });

    test('delete removes the row and later reads report not found', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $transaction = txRecord($household);

        $this->actingAs($owner)->deleteJson("/api/transactions/{$transaction->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $transaction->id);

        expect(Transaction::find($transaction->id))->toBeNull();

        // The row is gone, so implicit binding yields the framework 404
        // (same as every existing endpoint with route-model binding).
        $this->actingAs($owner)->getJson("/api/transactions/{$transaction->id}")
            ->assertStatus(404);
    });

    test('unknown ULIDs report not found without leaking', function () {
        $owner = User::factory()->create();
        txHousehold($owner);
        $ghost = (string) Str::ulid();

        $this->actingAs($owner)->getJson("/api/transactions/{$ghost}")->assertStatus(404);
        $this->actingAs($owner)->patchJson("/api/transactions/{$ghost}", ['description' => 'x'])->assertStatus(404);
        $this->actingAs($owner)->deleteJson("/api/transactions/{$ghost}")->assertStatus(404);
    });

    test('pagination is bounded and deterministic', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);

        for ($day = 1; $day <= 20; $day++) {
            txRecord($household, ['occurred_on' => sprintf('2026-10-%02d', $day)]);
        }

        $first = $this->actingAs($owner)->getJson('/api/transactions?per_page=5');

        $first->assertOk();
        $first->assertJsonPath('data.meta.total', 20);
        $first->assertJsonPath('data.meta.per_page', 5);
        $first->assertJsonPath('data.meta.last_page', 4);
        $first->assertJsonPath('data.meta.current_page', 1);
        expect($first->json('data.data'))->toHaveCount(5);

        $second = $this->actingAs($owner)->getJson('/api/transactions?per_page=5&page=2');
        expect($second->json('data.meta.current_page'))->toBe(2);

        $expected = Transaction::forHousehold($household)
            ->orderBy('occurred_on', 'desc')
            ->orderBy('id', 'desc')
            ->pluck('id')
            ->all();

        $paged = array_merge(
            $first->json('data.data'),
            $second->json('data.data'),
            $this->actingAs($owner)->getJson('/api/transactions?per_page=5&page=3')->json('data.data'),
            $this->actingAs($owner)->getJson('/api/transactions?per_page=5&page=4')->json('data.data'),
        );

        expect(array_column($paged, 'id'))->toEqual($expected);

        $this->actingAs($owner)->getJson('/api/transactions?per_page=0')
            ->assertStatus(422)->assertJsonValidationErrors(['per_page']);

        $this->actingAs($owner)->getJson('/api/transactions?per_page=101')
            ->assertStatus(422)->assertJsonValidationErrors(['per_page']);

        $this->actingAs($owner)->getJson('/api/transactions?sort_by=hacker')
            ->assertStatus(422)->assertJsonValidationErrors(['sort_by']);

        $this->actingAs($owner)->getJson('/api/transactions?sort_dir=sideways')
            ->assertStatus(422)->assertJsonValidationErrors(['sort_dir']);
    });

    test('filters combine deterministically', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $food = Category::factory()->for($household)->create(['name' => 'Makan']);
        $fare = Category::factory()->for($household)->create(['name' => 'Transport']);

        txRecord($household, ['type' => 'expense', 'occurred_on' => '2026-10-03', 'category_id' => $food->id, 'description' => 'sarapan']);
        txRecord($household, ['type' => 'expense', 'occurred_on' => '2026-10-10', 'category_id' => $fare->id, 'description' => 'ojek']);
        txRecord($household, ['type' => 'income', 'occurred_on' => '2026-10-10', 'category_id' => $food->id, 'description' => 'gaji']);
        txRecord($household, ['type' => 'expense', 'occurred_on' => '2026-09-28', 'category_id' => $food->id, 'description' => 'bulan lalu']);

        $range = $this->actingAs($owner)->getJson('/api/transactions?date_from=2026-10-01&date_to=2026-10-31');
        expect($range->json('data.meta.total'))->toBe(3);

        $category = $this->actingAs($owner)->getJson("/api/transactions?category_id={$food->id}");
        expect($category->json('data.meta.total'))->toBe(3);

        $type = $this->actingAs($owner)->getJson('/api/transactions?type=income');
        expect($type->json('data.meta.total'))->toBe(1);

        $combined = $this->actingAs($owner)->getJson(
            "/api/transactions?date_from=2026-10-01&date_to=2026-10-31&category_id={$food->id}&type=expense"
        );
        expect($combined->json('data.meta.total'))->toBe(1);
        expect($combined->json('data.data.0.description'))->toBe('sarapan');

        $this->actingAs($owner)->getJson('/api/transactions?date_from=2026-10-31&date_to=2026-10-01')
            ->assertStatus(422)->assertJsonValidationErrors(['date_to']);

        $this->actingAs($owner)->getJson('/api/transactions?sort_by=amount&sort_dir=asc');
        $amounts = array_map(
            fn (array $row): string => $row['amount'],
            $this->actingAs($owner)->getJson('/api/transactions?sort_by=amount&sort_dir=asc&per_page=100')->json('data.data')
        );
        $sorted = $amounts;
        sort($sorted, SORT_NUMERIC);
        expect($amounts)->toEqual($sorted);
    });

    test('responses expose exact keys without internal wiring', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $category = Category::factory()->for($household)->create();
        $transaction = txRecord($household, ['category_id' => $category->id]);

        $data = $this->actingAs($owner)->getJson("/api/transactions/{$transaction->id}")->json('data');

        expect(array_keys($data))->toEqual([
            'id',
            'household_id',
            'type',
            'amount',
            'occurred_on',
            'description',
            'wallet',
            'wallet_id',
            'destination_wallet_id',
            'source_wallet',
            'destination_wallet',
            'category',
            'bill_payment',
            'created_at',
            'updated_at',
        ]);
        expect(array_keys($data['category']))->toEqual(['id', 'name', 'icon']);
        // D-08: derived morph linkage, nullable, null for non-bill rows.
        expect($data['bill_payment'])->toBeNull();
    });

    test('ledger writes never store derived aggregates', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $category = Category::factory()->for($household)->create();

        txRecord($household, ['category_id' => $category->id]);
        txRecord($household, ['category_id' => $category->id]);

        expect(Budget::forHousehold($household)->count())->toBe(0);
        expect(Schema::getColumnListing('budgets'))->not->toContain('spent');
    });

    test('listing runs a bounded number of queries', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $category = Category::factory()->for($household)->create();

        for ($i = 0; $i < 5; $i++) {
            txRecord($household, ['category_id' => $category->id]);
        }

        DB::enableQueryLog();

        $this->actingAs($owner)->getJson('/api/transactions')->assertOk();

        expect(count(DB::getQueryLog()))->toBeLessThan(10);
    });
});

// Phase 15.2.3 (Opsi B): mengunci kontrak HTTP PATCH /api/transactions
// untuk gerbang transfer & perubahan tipe — sebelumnya hanya teruji di
// level service. Nol perubahan kontrak: test saja.
describe('Transactions API update: wallet movement and type changes', function () {
    test('update moves a transaction across wallets and revalidates both balances', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $wallet = txWallet($household, ['opening_balance' => '1000000.00']);
        $other = txWallet($household, ['name' => 'Bank Lain', 'opening_balance' => '500000.00']);
        $transaction = Transaction::factory()->for($household)->create([
            'type' => Transaction::TYPE_EXPENSE,
            'category_id' => null,
            'wallet_id' => $wallet->id,
            'amount' => '150000.00',
            'occurred_on' => '2026-10-05',
        ]);

        $response = $this->actingAs($owner)->patchJson("/api/transactions/{$transaction->id}", [
            'wallet_id' => $other->id,
            'wallet' => 'Bank Lain',
        ]);

        $response->assertOk()->assertJsonPath('data.wallet_id', $other->id);

        // Delta gabungan atomik: expense lama dilepas dari dompet sumber
        // dan diterapkan ke dompet baru — kedua sisi divalidasi ulang.
        expect($transaction->refresh()->wallet_id)->toBe($other->id);
        expect($this->actingAs($owner)->getJson("/api/wallets/{$wallet->id}")->json('data.balance'))
            ->toBe('1000000.00');
        expect($this->actingAs($owner)->getJson("/api/wallets/{$other->id}")->json('data.balance'))
            ->toBe('350000.00');
    });

    test('update converts income into a transfer with two deltas and a null category', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $source = txWallet($household, ['opening_balance' => '1000000.00']);
        $destination = txWallet($household, ['name' => 'Bank', 'opening_balance' => '250000.00']);
        $income = Transaction::factory()->for($household)->create([
            'type' => Transaction::TYPE_INCOME,
            'category_id' => null,
            'wallet_id' => $source->id,
            'amount' => '150000.00',
            'occurred_on' => '2026-10-05',
        ]);

        $response = $this->actingAs($owner)->patchJson("/api/transactions/{$income->id}", [
            'type' => Transaction::TYPE_TRANSFER,
            'wallet_id' => $source->id,
            'destination_wallet_id' => $destination->id,
            'category_id' => null,
        ]);

        $response->assertOk();
        expect($response->json('data.type'))->toBe('transfer');
        expect($response->json('data.destination_wallet_id'))->toBe($destination->id);
        expect($response->json('data.category'))->toBeNull();

        // Income +150k di sumber dibatalkan (saldo 1150000 → 1000000)
        // dan posting transfer baru menerapkannya sebagai keluaran
        // (−150k): delta gabungan sumber = −300k → 850000; tujuan +150k.
        expect($this->actingAs($owner)->getJson("/api/wallets/{$source->id}")->json('data.balance'))
            ->toBe('850000.00');
        expect($this->actingAs($owner)->getJson("/api/wallets/{$destination->id}")->json('data.balance'))
            ->toBe('400000.00');
    });

    test('update converts a transfer into an expense and clears the destination', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $source = txWallet($household, ['opening_balance' => '1000000.00']);
        $destination = txWallet($household, ['name' => 'Bank', 'opening_balance' => '250000.00']);
        $transfer = Transaction::factory()->for($household)->create([
            'type' => Transaction::TYPE_TRANSFER,
            'category_id' => null,
            'wallet_id' => $source->id,
            'destination_wallet_id' => $destination->id,
            'amount' => '100000.00',
            'occurred_on' => '2026-10-05',
        ]);

        // destination_wallet_id: null WAJIB eksplisit — key absen berarti
        // tujuan lama terbawa (change() memakai array_key_exists) dan
        // postingOf menolak destination pada non-transfer.
        $response = $this->actingAs($owner)->patchJson("/api/transactions/{$transfer->id}", [
            'type' => Transaction::TYPE_EXPENSE,
            'wallet_id' => $source->id,
            'destination_wallet_id' => null,
            'category_id' => null,
        ]);

        $response->assertOk();
        expect($response->json('data.type'))->toBe('expense');
        expect($response->json('data.destination_wallet_id'))->toBeNull();

        $fresh = $transfer->refresh();
        expect($fresh->type)->toBe('expense');
        expect($fresh->destination_wallet_id)->toBeNull();

        // Sumber tetap (expense menggantikan transfer di sisi yang sama);
        // tujuan kehilangan kredit transfer lama.
        expect($this->actingAs($owner)->getJson("/api/wallets/{$source->id}")->json('data.balance'))
            ->toBe('900000.00');
        expect($this->actingAs($owner)->getJson("/api/wallets/{$destination->id}")->json('data.balance'))
            ->toBe('250000.00');
    });

    test('update that would drive a balance negative fails with INSUFFICIENT_FUNDS', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $wallet = txWallet($household, ['opening_balance' => '50000.00']);
        $expense = Transaction::factory()->for($household)->create([
            'type' => Transaction::TYPE_EXPENSE,
            'category_id' => null,
            'wallet_id' => $wallet->id,
            'amount' => '30000.00',
            'occurred_on' => '2026-10-05',
        ]);

        $this->actingAs($owner)->patchJson("/api/transactions/{$expense->id}", ['amount' => 100000])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INSUFFICIENT_FUNDS');

        // Atomik: data & saldo tidak berubah setelah penolakan.
        expect((string) $expense->refresh()->amount)->toBe('30000.00');
        expect($this->actingAs($owner)->getJson("/api/wallets/{$wallet->id}")->json('data.balance'))
            ->toBe('20000.00');
    });

    test('update of a non-transfer carrying a destination wallet fails with TRANSACTION_INVALID', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $wallet = txWallet($household, ['opening_balance' => '1000000.00']);
        $other = txWallet($household, ['name' => 'Bank Lain', 'opening_balance' => '500000.00']);
        $expense = Transaction::factory()->for($household)->create([
            'type' => Transaction::TYPE_EXPENSE,
            'category_id' => null,
            'wallet_id' => $wallet->id,
            'amount' => '150000.00',
            'occurred_on' => '2026-10-05',
        ]);

        $this->actingAs($owner)->patchJson("/api/transactions/{$expense->id}", [
            'destination_wallet_id' => $other->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TRANSACTION_INVALID');

        expect($expense->refresh()->destination_wallet_id)->toBeNull();
        expect($this->actingAs($owner)->getJson("/api/wallets/{$wallet->id}")->json('data.balance'))
            ->toBe('850000.00');
    });

    test('update of a transfer without a destination fails with TRANSACTION_INVALID', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $source = txWallet($household, ['opening_balance' => '1000000.00']);
        $destination = txWallet($household, ['name' => 'Bank', 'opening_balance' => '250000.00']);
        $transfer = Transaction::factory()->for($household)->create([
            'type' => Transaction::TYPE_TRANSFER,
            'category_id' => null,
            'wallet_id' => $source->id,
            'destination_wallet_id' => $destination->id,
            'amount' => '100000.00',
            'occurred_on' => '2026-10-05',
        ]);

        $this->actingAs($owner)->patchJson("/api/transactions/{$transfer->id}", [
            'destination_wallet_id' => null,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TRANSACTION_INVALID');

        $fresh = $transfer->refresh();
        expect($fresh->type)->toBe('transfer');
        expect($fresh->destination_wallet_id)->toBe($destination->id);
        expect($this->actingAs($owner)->getJson("/api/wallets/{$source->id}")->json('data.balance'))
            ->toBe('900000.00');
        expect($this->actingAs($owner)->getJson("/api/wallets/{$destination->id}")->json('data.balance'))
            ->toBe('350000.00');
    });

    test('update cannot detach a posted wallet', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $wallet = txWallet($household, ['opening_balance' => '1000000.00']);
        $expense = Transaction::factory()->for($household)->create([
            'type' => Transaction::TYPE_EXPENSE,
            'category_id' => null,
            'wallet_id' => $wallet->id,
            'amount' => '150000.00',
            'occurred_on' => '2026-10-05',
        ]);

        // Menolak penghapusan dompet terposting: baik lewat validasi
        // FormRequest (wallet_id tanpa nullable) maupun guard service —
        // keduanya berujung 422 tanpa mengubah row.
        $this->actingAs($owner)->patchJson("/api/transactions/{$expense->id}", ['wallet_id' => null])
            ->assertStatus(422);

        $fresh = $expense->refresh();
        expect($fresh->wallet_id)->toBe($wallet->id);
        expect((string) $fresh->amount)->toBe('150000.00');
        expect($this->actingAs($owner)->getJson("/api/wallets/{$wallet->id}")->json('data.balance'))
            ->toBe('850000.00');
    });

    test('update with a category that no longer matches the new type fails with TRANSACTION_INVALID', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $wallet = txWallet($household, ['opening_balance' => '1000000.00']);
        $expenseCategory = Category::factory()->for($household)->create(['type' => Category::TYPE_EXPENSE]);
        $expense = Transaction::factory()->for($household)->create([
            'type' => Transaction::TYPE_EXPENSE,
            'category_id' => $expenseCategory->id,
            'wallet_id' => $wallet->id,
            'amount' => '150000.00',
            'occurred_on' => '2026-10-05',
        ]);

        // Kategori tidak dikirim → kategori lama terbawa dan re-pairing
        // menolak kategori expense pada type income.
        $this->actingAs($owner)->patchJson("/api/transactions/{$expense->id}", [
            'type' => Transaction::TYPE_INCOME,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TRANSACTION_INVALID');

        $fresh = $expense->refresh();
        expect($fresh->type)->toBe('expense');
        expect($fresh->category_id)->toBe($expenseCategory->id);
    });

    test('update of a bill payment stays locked with BILL_PAYMENT_LOCKED', function () {
        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $wallet = txWallet($household, ['opening_balance' => '1000000.00']);
        $category = Category::factory()->for($household)->create(['type' => Category::TYPE_EXPENSE]);
        $bill = Bill::factory()->for($household)->create([
            'category_id' => $category->id,
            'title' => 'Internet Rumah',
            'amount' => '150000.00',
            'due_date' => '2026-12-05',
            'is_active' => true,
        ]);
        $payment = Transaction::factory()->for($household)->create([
            'type' => Transaction::TYPE_EXPENSE,
            'category_id' => $category->id,
            'wallet_id' => $wallet->id,
            'amount' => '150000.00',
            'occurred_on' => '2026-10-10',
            'source_type' => Bill::class,
            'source_id' => $bill->id,
        ]);

        foreach ([
            ['type' => Transaction::TYPE_TRANSFER],
            ['amount' => 1],
            ['wallet_id' => (string) Str::ulid()],
        ] as $payload) {
            $this->actingAs($owner)->patchJson("/api/transactions/{$payment->id}", $payload)
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'BILL_PAYMENT_LOCKED');
        }

        $fresh = $payment->refresh();
        expect($fresh->type)->toBe('expense');
        expect((string) $fresh->amount)->toBe('150000.00');
        expect($fresh->wallet_id)->toBe($wallet->id);
        expect($fresh->source_type)->toBe(Bill::class);
        expect($this->actingAs($owner)->getJson("/api/wallets/{$wallet->id}")->json('data.balance'))
            ->toBe('850000.00');
    });
});

describe('TransactionPolicy', function () {
    test('owners manage their household ledger, members read, outsiders cannot', function () {
        $policy = new TransactionPolicy;

        $owner = User::factory()->create();
        $household = txHousehold($owner);
        $transaction = txRecord($household);

        $member = User::factory()->create();
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);

        $outsider = User::factory()->create();
        $foreign = txHousehold($outsider, 'Asing');
        $foreignTransaction = txRecord($foreign);

        expect($policy->viewAny($owner, $household))->toBeTrue();
        expect($policy->view($owner, $transaction))->toBeTrue();
        expect($policy->create($owner, $household))->toBeTrue();
        expect($policy->update($owner, $transaction))->toBeTrue();
        expect($policy->delete($owner, $transaction))->toBeTrue();

        expect($policy->viewAny($member, $household))->toBeTrue();
        expect($policy->view($member, $transaction))->toBeTrue();
        expect($policy->create($member, $household))->toBeFalse();
        expect($policy->update($member, $transaction))->toBeFalse();
        expect($policy->delete($member, $transaction))->toBeFalse();

        expect($policy->viewAny($outsider, $household))->toBeFalse();
        expect($policy->view($outsider, $transaction))->toBeFalse();
        expect($policy->create($outsider, $household))->toBeFalse();
        expect($policy->update($outsider, $transaction))->toBeFalse();
        expect($policy->delete($outsider, $transaction))->toBeFalse();

        expect($policy->view($owner, $foreignTransaction))->toBeFalse();
        expect($policy->update($owner, $foreignTransaction))->toBeFalse();
        expect($policy->delete($owner, $foreignTransaction))->toBeFalse();
    });
});
