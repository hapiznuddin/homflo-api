<?php

use App\Models\Category;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function wHousehold(User $owner, string $name = 'Rumah Dompet', string $role = 'owner'): Household
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

function wWallet(Household $household, array $overrides = []): Wallet
{
    return Wallet::factory()
        ->for($household)
        ->create(array_merge([
            'opening_balance' => '100000000.00',
        ], $overrides));
}

function wCategory(Household $household, string $type, string $name = 'Kategori Dompet'): Category
{
    return Category::factory()->for($household)->create([
        'name' => $name.' '.Str::random(4),
        'type' => $type,
    ]);
}

function wBalance($test, User $owner, Wallet $wallet): string
{
    return $test->actingAs($owner)->getJson("/api/wallets/{$wallet->id}")->json('data.balance');
}

describe('Wallets API authentication and household isolation', function () {
    test('guest cannot access protected wallet endpoints', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $wallet = wWallet($household);

        $this->getJson('/api/wallets')->assertStatus(401);
        $this->postJson('/api/wallets', [])->assertStatus(401);
        $this->getJson("/api/wallets/{$wallet->id}")->assertStatus(401);
        $this->patchJson("/api/wallets/{$wallet->id}", [])->assertStatus(401);
        $this->postJson("/api/wallets/{$wallet->id}/deactivate")->assertStatus(401);
        $this->deleteJson("/api/wallets/{$wallet->id}")->assertStatus(401);
        $this->getJson("/api/wallets/{$wallet->id}/adjustments")->assertStatus(401);
        $this->postJson("/api/wallets/{$wallet->id}/adjustments", [])->assertStatus(401);
    });

    test('user without household receives household not found', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/wallets')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');

        $this->actingAs($user)->postJson('/api/wallets', [
            'name' => 'Kas',
            'type' => 'cash',
            'opening_balance' => 0,
        ])->assertStatus(404);
    });

    test('cross-household wallets never leak existence', function () {
        $owner = User::factory()->create();
        wHousehold($owner);

        $stranger = User::factory()->create();
        $foreignHousehold = wHousehold($stranger, 'Asing');
        $foreign = wWallet($foreignHousehold);

        $this->actingAs($owner)->getJson("/api/wallets/{$foreign->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'WALLET_NOT_FOUND');

        $this->actingAs($owner)->patchJson("/api/wallets/{$foreign->id}", ['name' => 'x'])
            ->assertStatus(404);

        $this->actingAs($owner)->deleteJson("/api/wallets/{$foreign->id}")
            ->assertStatus(404);

        $this->actingAs($owner)->getJson("/api/wallets/{$foreign->id}/adjustments")
            ->assertStatus(404);

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => 10000,
            'occurred_on' => '2026-10-05',
            'wallet_id' => $foreign->id,
        ])->assertStatus(404)
            ->assertJsonPath('error.code', 'WALLET_NOT_FOUND');
    });

    test('member reads wallets and adjustments but cannot mutate them', function () {
        $member = User::factory()->create();
        $household = wHousehold($member, 'Keluarga', 'member');
        $wallet = wWallet($household);

        $this->actingAs($member)->getJson('/api/wallets')->assertOk();
        $this->actingAs($member)->getJson("/api/wallets/{$wallet->id}")->assertOk();
        $this->actingAs($member)->getJson("/api/wallets/{$wallet->id}/adjustments")->assertOk();

        $this->actingAs($member)->postJson('/api/wallets', [
            'name' => 'Baru',
            'type' => 'cash',
            'opening_balance' => 0,
        ])->assertStatus(404);

        $this->actingAs($member)->patchJson("/api/wallets/{$wallet->id}", ['name' => 'x'])
            ->assertStatus(404);

        $this->actingAs($member)->postJson("/api/wallets/{$wallet->id}/deactivate")
            ->assertStatus(404);

        $this->actingAs($member)->deleteJson("/api/wallets/{$wallet->id}")
            ->assertStatus(404);

        $this->actingAs($member)->postJson("/api/wallets/{$wallet->id}/adjustments", [
            'amount' => 1000,
            'reason' => 'koreksi',
        ])->assertStatus(404);
    });
});

describe('Wallets API validation and persistence', function () {
    test('store validates required fields and wallet types', function () {
        $owner = User::factory()->create();
        wHousehold($owner);

        $this->actingAs($owner)->postJson('/api/wallets', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'type', 'opening_balance']);

        $this->actingAs($owner)->postJson('/api/wallets', [
            'name' => 'Kas',
            'type' => 'crypto',
            'opening_balance' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors(['type']);

        $this->actingAs($owner)->postJson('/api/wallets', [
            'name' => 'Kas',
            'type' => 'cash',
            'opening_balance' => -1,
        ])->assertStatus(422)->assertJsonValidationErrors(['opening_balance']);
    });

    test('store persists exact values and derives balance from opening', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);

        foreach (['cash', 'bank', 'e-wallet'] as $type) {
            $response = $this->actingAs($owner)->postJson('/api/wallets', [
                'name' => "Dompet {$type}",
                'type' => $type,
                'opening_balance' => '250000.75',
            ]);

            $response->assertStatus(201)->assertJsonPath('success', true);

            $data = $response->json('data');

            expect($data['name'])->toBe("Dompet {$type}");
            expect($data['type'])->toBe($type);
            expect($data['opening_balance'])->toBe('250000.75');
            expect($data['is_active'])->toBeTrue();
            expect($data['balance'])->toBe('250000.75');
            expect($data['household_id'])->toBe($household->id);
        }
    });

    test('names stay unique per household including inactive wallets', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $wallet = wWallet($household, ['name' => 'Kas']);

        $this->actingAs($owner)->postJson('/api/wallets', [
            'name' => 'Kas',
            'type' => 'bank',
            'opening_balance' => 0,
        ])->assertStatus(422)->assertJsonPath('error.code', 'WALLET_ALREADY_EXISTS');

        $this->actingAs($owner)->postJson("/api/wallets/{$wallet->id}/deactivate")->assertOk();
        expect($wallet->refresh()->is_active)->toBeFalse();

        $this->actingAs($owner)->postJson('/api/wallets', [
            'name' => 'Kas',
            'type' => 'bank',
            'opening_balance' => 0,
        ])->assertStatus(422)->assertJsonPath('error.code', 'WALLET_ALREADY_EXISTS');

        $this->actingAs($owner)->patchJson("/api/wallets/{$wallet->id}", ['name' => 'Kas'])
            ->assertOk();

        $other = wWallet($household, ['name' => 'Bank']);

        $this->actingAs($owner)->patchJson("/api/wallets/{$other->id}", ['name' => 'Kas'])
            ->assertStatus(422)->assertJsonPath('error.code', 'WALLET_ALREADY_EXISTS');

        $neighbor = User::factory()->create();
        $foreignHousehold = wHousehold($neighbor, 'Tetangga');

        $this->actingAs($neighbor)->postJson('/api/wallets', [
            'name' => 'Kas',
            'type' => 'cash',
            'opening_balance' => 0,
        ])->assertStatus(201);
        expect($foreignHousehold->wallets()->count())->toBe(1);
    });

    test('update changes metadata but never activation state', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $wallet = wWallet($household, ['name' => 'Kas', 'type' => 'cash']);

        $response = $this->actingAs($owner)->patchJson("/api/wallets/{$wallet->id}", [
            'name' => 'Kas Tunai',
            'type' => 'bank',
        ]);

        $response->assertOk();
        expect($response->json('data.name'))->toBe('Kas Tunai');
        expect($response->json('data.type'))->toBe('bank');

        $this->actingAs($owner)->patchJson("/api/wallets/{$wallet->id}", ['is_active' => false])
            ->assertStatus(422)->assertJsonValidationErrors(['is_active']);
    });

    test('inactive wallets stay readable but reject new postings', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $wallet = wWallet($household, ['name' => 'Kas']);
        $incomeCategory = wCategory($household, Category::TYPE_INCOME, 'Gaji');

        $this->actingAs($owner)->postJson("/api/wallets/{$wallet->id}/deactivate")->assertOk();
        expect($wallet->refresh()->is_active)->toBeFalse();

        $this->actingAs($owner)->getJson("/api/wallets/{$wallet->id}")->assertOk();
        $this->actingAs($owner)->getJson('/api/wallets')->assertOk();

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'income',
            'amount' => 10000,
            'occurred_on' => '2026-10-05',
            'category_id' => $incomeCategory->id,
            'wallet_id' => $wallet->id,
        ])->assertStatus(404)->assertJsonPath('error.code', 'WALLET_NOT_FOUND');

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => 10000,
            'occurred_on' => '2026-10-05',
            'wallet_id' => $wallet->id,
        ])->assertStatus(404)->assertJsonPath('error.code', 'WALLET_NOT_FOUND');

        $destination = wWallet($household, ['name' => 'Bank']);

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'transfer',
            'amount' => 10000,
            'occurred_on' => '2026-10-05',
            'wallet_id' => $wallet->id,
            'destination_wallet_id' => $destination->id,
        ])->assertStatus(404)->assertJsonPath('error.code', 'WALLET_NOT_FOUND');

        $this->actingAs($owner)->postJson("/api/wallets/{$wallet->id}/adjustments", [
            'amount' => 1000,
            'reason' => 'koreksi',
        ])->assertStatus(404)->assertJsonPath('error.code', 'WALLET_NOT_FOUND');
    });

    test('wallets with history cannot be deleted but unused ones can', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $used = wWallet($household, ['name' => 'Dipakai', 'opening_balance' => '50000.00']);
        $unused = wWallet($household, ['name' => 'Kosong']);
        $category = wCategory($household, Category::TYPE_EXPENSE);

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => '10000.00',
            'occurred_on' => '2026-10-05',
            'category_id' => $category->id,
            'wallet_id' => $used->id,
        ])->assertStatus(201);

        $this->actingAs($owner)->deleteJson("/api/wallets/{$used->id}")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WALLET_IN_USE');

        expect(Wallet::where('household_id', $household->id)->count())->toBe(2);

        $this->actingAs($owner)->deleteJson("/api/wallets/{$unused->id}")->assertOk();

        expect(Wallet::where('household_id', $household->id)->count())->toBe(1);
    });

    test('list exposes derived balances without N+1 query growth', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $category = wCategory($household, Category::TYPE_EXPENSE);

        for ($i = 0; $i < 5; $i++) {
            $wallet = wWallet($household, ['name' => "Dompet {$i}", 'opening_balance' => '10000.00']);

            $this->actingAs($owner)->postJson('/api/transactions', [
                'type' => 'expense',
                'amount' => '1000.00',
                'occurred_on' => '2026-10-05',
                'category_id' => $category->id,
                'wallet_id' => $wallet->id,
            ])->assertStatus(201);
        }

        DB::enableQueryLog();

        $response = $this->actingAs($owner)->getJson('/api/wallets')->assertOk();

        expect(count(DB::getQueryLog()))->toBeLessThan(15);
        expect($response->json('data'))->toHaveCount(5);

        foreach ($response->json('data') as $row) {
            expect($row['balance'])->toBe('9000.00');
        }
    });
});

describe('Category type contracts', function () {
    test('income accepts only income categories, expense only expense categories', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $wallet = wWallet($household);
        $incomeCategory = wCategory($household, Category::TYPE_INCOME, 'Gaji');
        $expenseCategory = wCategory($household, Category::TYPE_EXPENSE, 'Dapur');

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'income',
            'amount' => 100000,
            'occurred_on' => '2026-10-05',
            'category_id' => $incomeCategory->id,
            'wallet_id' => $wallet->id,
        ])->assertStatus(201);

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => 100000,
            'occurred_on' => '2026-10-05',
            'category_id' => $expenseCategory->id,
            'wallet_id' => $wallet->id,
        ])->assertStatus(201);

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => 100000,
            'occurred_on' => '2026-10-05',
            'category_id' => $incomeCategory->id,
            'wallet_id' => $wallet->id,
        ])->assertStatus(422)->assertJsonPath('error.code', 'TRANSACTION_INVALID');

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'income',
            'amount' => 100000,
            'occurred_on' => '2026-10-05',
            'category_id' => $expenseCategory->id,
            'wallet_id' => $wallet->id,
        ])->assertStatus(422)->assertJsonPath('error.code', 'TRANSACTION_INVALID');

        expect(Transaction::where('household_id', $household->id)->count())->toBe(2);
    });

    test('legacy categories without a type stay usable for income and expense', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $wallet = wWallet($household);
        $legacy = Category::factory()->for($household)->create(['name' => 'Warisan', 'type' => null]);

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'income',
            'amount' => 100000,
            'occurred_on' => '2026-10-05',
            'category_id' => $legacy->id,
            'wallet_id' => $wallet->id,
        ])->assertStatus(201);

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => 50000,
            'occurred_on' => '2026-10-05',
            'category_id' => $legacy->id,
            'wallet_id' => $wallet->id,
        ])->assertStatus(201);

        expect(wBalance($this, $owner, $wallet))->toBe('100050000.00');
    });

    test('budgets require expense categories including legacy untyped ones', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $incomeCategory = wCategory($household, Category::TYPE_INCOME, 'Gaji');
        $expenseCategory = wCategory($household, Category::TYPE_EXPENSE, 'Dapur');
        $legacy = Category::factory()->for($household)->create(['name' => 'Warisan', 'type' => null]);

        $this->actingAs($owner)->postJson('/api/budgets', [
            'category_id' => $expenseCategory->id,
            'year' => 2026,
            'month' => 10,
            'amount' => 500000,
        ])->assertStatus(201);

        $this->actingAs($owner)->postJson('/api/budgets', [
            'category_id' => $incomeCategory->id,
            'year' => 2026,
            'month' => 11,
            'amount' => 500000,
        ])->assertStatus(422)->assertJsonPath('error.code', 'CATEGORY_TYPE_MISMATCH');

        $this->actingAs($owner)->postJson('/api/budgets', [
            'category_id' => $legacy->id,
            'year' => 2026,
            'month' => 11,
            'amount' => 500000,
        ])->assertStatus(422)->assertJsonPath('error.code', 'CATEGORY_TYPE_MISMATCH');
    });

    test('category type cannot change once history exists, but legacy can be classified once', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $typed = wCategory($household, Category::TYPE_EXPENSE, 'Dapur');
        $legacy = Category::factory()->for($household)->create(['name' => 'Warisan', 'type' => null]);
        $wallet = wWallet($household);

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => 10000,
            'occurred_on' => '2026-10-05',
            'category_id' => $typed->id,
            'wallet_id' => $wallet->id,
        ])->assertStatus(201);

        $this->actingAs($owner)->patchJson("/api/categories/{$typed->id}", ['type' => 'income'])
            ->assertStatus(422)->assertJsonPath('error.code', 'CATEGORY_TYPE_MISMATCH');

        // Legacy classification works exactly once in each direction while unused.
        $this->actingAs($owner)->patchJson("/api/categories/{$legacy->id}", ['type' => 'income'])
            ->assertOk();
        expect($legacy->refresh()->type)->toBe('income');

        $unused = Category::factory()->for($household)->create(['name' => 'Kosong', 'type' => 'expense']);

        $this->actingAs($owner)->patchJson("/api/categories/{$unused->id}", ['type' => 'income'])
            ->assertOk();
    });

    test('transfers never carry a category', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $source = wWallet($household, ['name' => 'Kas']);
        $destination = wWallet($household, ['name' => 'Bank']);
        $category = wCategory($household, Category::TYPE_EXPENSE);

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'transfer',
            'amount' => 10000,
            'occurred_on' => '2026-10-05',
            'category_id' => $category->id,
            'wallet_id' => $source->id,
            'destination_wallet_id' => $destination->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['category_id']);
    });
});

describe('Ledger balances and transfer contracts', function () {
    test('income raises and expense lowers the wallet balance exactly', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $wallet = wWallet($household, ['opening_balance' => '1000.00']);
        $incomeCategory = wCategory($household, Category::TYPE_INCOME, 'Gaji');
        $expenseCategory = wCategory($household, Category::TYPE_EXPENSE, 'Dapur');

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'income',
            'amount' => '250000.75',
            'occurred_on' => '2026-10-05',
            'category_id' => $incomeCategory->id,
            'wallet_id' => $wallet->id,
        ])->assertStatus(201);

        expect(wBalance($this, $owner, $wallet))->toBe('251000.75');

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => '50250.25',
            'occurred_on' => '2026-10-06',
            'category_id' => $expenseCategory->id,
            'wallet_id' => $wallet->id,
        ])->assertStatus(201);

        expect(wBalance($this, $owner, $wallet))->toBe('200750.50');
    });

    test('expense beyond the balance is rejected and stores nothing', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $wallet = wWallet($household, ['opening_balance' => '50000.00']);
        $expenseCategory = wCategory($household, Category::TYPE_EXPENSE);

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => '50000.01',
            'occurred_on' => '2026-10-05',
            'category_id' => $expenseCategory->id,
            'wallet_id' => $wallet->id,
        ])->assertStatus(422)->assertJsonPath('error.code', 'INSUFFICIENT_FUNDS');

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => '50000.00',
            'occurred_on' => '2026-10-05',
            'category_id' => $expenseCategory->id,
            'wallet_id' => $wallet->id,
        ])->assertStatus(201);

        expect(wBalance($this, $owner, $wallet))->toBe('0.00');
        expect(Transaction::where('household_id', $household->id)->count())->toBe(1);
    });

    test('transfers move the same amount and validate both wallets', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $source = wWallet($household, ['name' => 'Kas', 'opening_balance' => '100000.00']);
        $destination = wWallet($household, ['name' => 'Bank', 'opening_balance' => '10000.00']);

        $response = $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'transfer',
            'amount' => '25000.50',
            'occurred_on' => '2026-10-05',
            'wallet_id' => $source->id,
            'destination_wallet_id' => $destination->id,
        ]);

        $response->assertStatus(201);
        expect($response->json('data.type'))->toBe('transfer');
        expect($response->json('data.category'))->toBeNull();
        expect($response->json('data.source_wallet.id'))->toBe($source->id);
        expect($response->json('data.destination_wallet.id'))->toBe($destination->id);

        expect(wBalance($this, $owner, $source))->toBe('74999.50');
        expect(wBalance($this, $owner, $destination))->toBe('35000.50');

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'transfer',
            'amount' => 1000,
            'occurred_on' => '2026-10-05',
            'wallet_id' => $source->id,
            'destination_wallet_id' => $source->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['destination_wallet_id']);

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'transfer',
            'amount' => 1000,
            'occurred_on' => '2026-10-05',
            'wallet_id' => $source->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['destination_wallet_id']);

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'transfer',
            'amount' => 200000,
            'occurred_on' => '2026-10-05',
            'wallet_id' => $source->id,
            'destination_wallet_id' => $destination->id,
        ])->assertStatus(422)->assertJsonPath('error.code', 'INSUFFICIENT_FUNDS');

        expect(Transaction::where('household_id', $household->id)->count())->toBe(1);
    });

    test('transfers across households are rejected without leaking', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $source = wWallet($household, ['name' => 'Kas']);

        $stranger = User::factory()->create();
        $foreignHousehold = wHousehold($stranger, 'Asing');
        $foreign = wWallet($foreignHousehold, ['name' => 'Bank Asing']);

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'transfer',
            'amount' => 1000,
            'occurred_on' => '2026-10-05',
            'wallet_id' => $source->id,
            'destination_wallet_id' => $foreign->id,
        ])->assertStatus(404)->assertJsonPath('error.code', 'WALLET_NOT_FOUND');

        expect(Transaction::where('household_id', $household->id)->count())->toBe(0);
        expect(wBalance($this, $owner, $source))->toBe('100000000.00');
    });

    test('transfers never touch income, expense, or actual spending aggregates', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $category = wCategory($household, Category::TYPE_EXPENSE);
        $source = wWallet($household, ['name' => 'Kas', 'opening_balance' => '1000000.00']);
        $destination = wWallet($household, ['name' => 'Bank', 'opening_balance' => '0.00']);

        $this->actingAs($owner)->postJson('/api/budgets', [
            'category_id' => $category->id,
            'year' => 2026,
            'month' => 10,
            'amount' => 500000,
        ])->assertStatus(201);

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'transfer',
            'amount' => 250000,
            'occurred_on' => '2026-10-05',
            'wallet_id' => $source->id,
            'destination_wallet_id' => $destination->id,
        ])->assertStatus(201);

        $budgets = $this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10')->assertOk();

        expect($budgets->json('data.0.actual_spending'))->toBe('0.00');
        expect($budgets->json('meta.household.actual_spending'))->toBe('0.00');

        $income = $this->actingAs($owner)->getJson('/api/transactions?type=income');
        expect($income->json('data.meta.total'))->toBe(0);

        $expense = $this->actingAs($owner)->getJson('/api/transactions?type=expense');
        expect($expense->json('data.meta.total'))->toBe(0);

        $transfer = $this->actingAs($owner)->getJson('/api/transactions?type=transfer');
        expect($transfer->json('data.meta.total'))->toBe(1);
    });

    test('editing a transaction re-validates the affected balances', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $wallet = wWallet($household, ['opening_balance' => '100000.00']);
        $category = wCategory($household, Category::TYPE_EXPENSE);

        $response = $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => '60000.00',
            'occurred_on' => '2026-10-05',
            'category_id' => $category->id,
            'wallet_id' => $wallet->id,
        ]);

        $id = $response->json('data.id');

        $this->actingAs($owner)->patchJson("/api/transactions/{$id}", ['amount' => '120000.00'])
            ->assertStatus(422)->assertJsonPath('error.code', 'INSUFFICIENT_FUNDS');

        $this->actingAs($owner)->patchJson("/api/transactions/{$id}", ['amount' => '40000.00'])
            ->assertOk();

        expect(wBalance($this, $owner, $wallet))->toBe('60000.00');
    });

    test('moving a transaction across wallets validates both sides', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $full = wWallet($household, ['name' => 'Penuh', 'opening_balance' => '100000.00']);
        $thin = wWallet($household, ['name' => 'Tipis', 'opening_balance' => '10000.00']);
        $category = wCategory($household, Category::TYPE_EXPENSE);

        $response = $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => '60000.00',
            'occurred_on' => '2026-10-05',
            'category_id' => $category->id,
            'wallet_id' => $full->id,
        ]);

        $id = $response->json('data.id');

        $this->actingAs($owner)->patchJson("/api/transactions/{$id}", ['wallet_id' => $thin->id])
            ->assertStatus(422)->assertJsonPath('error.code', 'INSUFFICIENT_FUNDS');

        expect(wBalance($this, $owner, $full))->toBe('40000.00');
        expect(wBalance($this, $owner, $thin))->toBe('10000.00');
    });

    test('deleting income that funded later spending is rejected', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $wallet = wWallet($household, ['opening_balance' => '0.00']);
        $incomeCategory = wCategory($household, Category::TYPE_INCOME, 'Gaji');
        $expenseCategory = wCategory($household, Category::TYPE_EXPENSE, 'Dapur');

        $income = $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'income',
            'amount' => '100000.00',
            'occurred_on' => '2026-10-05',
            'category_id' => $incomeCategory->id,
            'wallet_id' => $wallet->id,
        ])->json('data.id');

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => '60000.00',
            'occurred_on' => '2026-10-06',
            'category_id' => $expenseCategory->id,
            'wallet_id' => $wallet->id,
        ])->assertStatus(201);

        $this->actingAs($owner)->deleteJson("/api/transactions/{$income}")
            ->assertStatus(422)->assertJsonPath('error.code', 'INSUFFICIENT_FUNDS');

        expect(Transaction::where('household_id', $household->id)->count())->toBe(2);
        expect(wBalance($this, $owner, $wallet))->toBe('40000.00');
    });

    test('historical transactions without a wallet stay readable and removable', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $legacy = Transaction::factory()->for($household)->create([
            'wallet_id' => null,
            'destination_wallet_id' => null,
        ]);

        $this->actingAs($owner)->getJson("/api/transactions/{$legacy->id}")->assertOk();

        $this->actingAs($owner)->patchJson("/api/transactions/{$legacy->id}", ['description' => 'masih bisa diubah'])
            ->assertOk();

        $this->actingAs($owner)->deleteJson("/api/transactions/{$legacy->id}")->assertOk();

        expect(Transaction::where('household_id', $household->id)->count())->toBe(0);
    });

    test('failed transfers roll back atomically without side effects', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $source = wWallet($household, ['name' => 'Kas', 'opening_balance' => '100000.00']);
        $destination = wWallet($household, ['name' => 'Bank', 'opening_balance' => '5000.00']);

        $this->actingAs($owner)->postJson("/api/wallets/{$destination->id}/deactivate")->assertOk();

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'transfer',
            'amount' => 10000,
            'occurred_on' => '2026-10-05',
            'wallet_id' => $source->id,
            'destination_wallet_id' => $destination->id,
        ])->assertStatus(404)->assertJsonPath('error.code', 'WALLET_NOT_FOUND');

        expect(Transaction::where('household_id', $household->id)->count())->toBe(0);
        expect(wBalance($this, $owner, $source))->toBe('100000.00');
        expect(wBalance($this, $owner, $destination))->toBe('5000.00');
    });
});

describe('Wallet adjustments and audit trail', function () {
    test('adjustments validate input and reject zero amounts', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $wallet = wWallet($household, ['opening_balance' => '10000.00']);

        $this->actingAs($owner)->postJson("/api/wallets/{$wallet->id}/adjustments", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount', 'reason']);

        $this->actingAs($owner)->postJson("/api/wallets/{$wallet->id}/adjustments", [
            'amount' => 0,
            'reason' => 'nol',
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
    });

    test('positive and negative adjustments record exact before/after balances', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $wallet = wWallet($household, ['opening_balance' => '10000.00']);

        $up = $this->actingAs($owner)->postJson("/api/wallets/{$wallet->id}/adjustments", [
            'amount' => '2500.50',
            'reason' => 'Setoran awal kurang catat',
        ]);

        $up->assertStatus(201);
        expect($up->json('data.amount'))->toBe('2500.50');
        expect($up->json('data.balance_before'))->toBe('10000.00');
        expect($up->json('data.balance_after'))->toBe('12500.50');
        expect($up->json('data.created_by.id'))->toBe($owner->id);
        expect($up->json('data.reason'))->toBe('Setoran awal kurang catat');

        $down = $this->actingAs($owner)->postJson("/api/wallets/{$wallet->id}/adjustments", [
            'amount' => '-3000.25',
            'reason' => 'Selisih kas opname',
        ]);

        $down->assertStatus(201);
        expect($down->json('data.balance_before'))->toBe('12500.50');
        expect($down->json('data.balance_after'))->toBe('9500.25');

        expect(wBalance($this, $owner, $wallet))->toBe('9500.25');
    });

    test('adjustments that would go negative are rejected without side effects', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $wallet = wWallet($household, ['opening_balance' => '1000.00']);

        $this->actingAs($owner)->postJson("/api/wallets/{$wallet->id}/adjustments", [
            'amount' => '-1000.01',
            'reason' => 'kebanyakan',
        ])->assertStatus(422)->assertJsonPath('error.code', 'INSUFFICIENT_FUNDS');

        expect(wBalance($this, $owner, $wallet))->toBe('1000.00');

        $list = $this->actingAs($owner)->getJson("/api/wallets/{$wallet->id}/adjustments")->assertOk();
        expect($list->json('data.meta.total'))->toBe(0);
    });

    test('adjustments never touch income, expense, or actual spending aggregates', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $wallet = wWallet($household, ['opening_balance' => '100000.00']);
        $category = wCategory($household, Category::TYPE_EXPENSE);

        $this->actingAs($owner)->postJson('/api/budgets', [
            'category_id' => $category->id,
            'year' => 2026,
            'month' => 10,
            'amount' => 500000,
        ])->assertStatus(201);

        $this->actingAs($owner)->postJson("/api/wallets/{$wallet->id}/adjustments", [
            'amount' => '77777.77',
            'reason' => 'koreksi',
        ])->assertStatus(201);

        $budgets = $this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10')->assertOk();

        expect($budgets->json('data.0.actual_spending'))->toBe('0.00');
        expect($budgets->json('meta.household.actual_spending'))->toBe('0.00');

        $income = $this->actingAs($owner)->getJson('/api/transactions?type=income');
        expect($income->json('data.meta.total'))->toBe(0);
    });

    test('adjustment history is listed newest first and has no mutation routes', function () {
        $owner = User::factory()->create();
        $household = wHousehold($owner);
        $wallet = wWallet($household, ['opening_balance' => '10000.00']);

        $first = $this->actingAs($owner)->postJson("/api/wallets/{$wallet->id}/adjustments", [
            'amount' => '100.00',
            'reason' => 'pertama',
        ])->json('data.id');

        $second = $this->actingAs($owner)->postJson("/api/wallets/{$wallet->id}/adjustments", [
            'amount' => '200.00',
            'reason' => 'kedua',
        ])->json('data.id');

        $list = $this->actingAs($owner)->getJson("/api/wallets/{$wallet->id}/adjustments")->assertOk();

        expect($list->json('data.meta.total'))->toBe(2);
        expect($list->json('data.data.0.id'))->toBe($second);
        expect($list->json('data.data.1.id'))->toBe($first);

        $this->actingAs($owner)->patchJson("/api/wallets/{$wallet->id}/adjustments/{$first}", ['reason' => 'ubah'])
            ->assertStatus(404);

        $this->actingAs($owner)->deleteJson("/api/wallets/{$wallet->id}/adjustments/{$first}")
            ->assertStatus(404);
    });
});

describe('Owner-only finance writes across the household ledger', function () {
    test('members are rejected from every financial write surface', function () {
        $member = User::factory()->create();
        $household = wHousehold($member, 'Keluarga', 'member');
        $wallet = wWallet($household);
        $category = wCategory($household, Category::TYPE_EXPENSE);

        $this->actingAs($member)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => 1000,
            'occurred_on' => '2026-10-05',
            'wallet_id' => $wallet->id,
        ])->assertStatus(404);

        $this->actingAs($member)->postJson('/api/transactions', [
            'type' => 'transfer',
            'amount' => 1000,
            'occurred_on' => '2026-10-05',
            'wallet_id' => $wallet->id,
            'destination_wallet_id' => $wallet->id,
        ])->assertStatus(422);

        $this->actingAs($member)->postJson('/api/categories', [
            'name' => 'X',
            'type' => 'expense',
        ])->assertStatus(404);

        $this->actingAs($member)->patchJson("/api/categories/{$category->id}", ['name' => 'Y'])
            ->assertStatus(404);

        $this->actingAs($member)->deleteJson("/api/categories/{$category->id}")
            ->assertStatus(404);

        $this->actingAs($member)->postJson('/api/budgets', [
            'category_id' => $category->id,
            'year' => 2026,
            'month' => 10,
            'amount' => 1000,
        ])->assertStatus(404);
    });

    test('owners write only inside their own household', function () {
        $owner = User::factory()->create();
        $mine = wHousehold($owner);

        $stranger = User::factory()->create();
        $theirs = wHousehold($stranger, 'Tetangga');
        $theirWallet = wWallet($theirs);
        $theirCategory = wCategory($theirs, Category::TYPE_EXPENSE);

        $this->actingAs($owner)->postJson('/api/budgets', [
            'category_id' => $theirCategory->id,
            'year' => 2026,
            'month' => 10,
            'amount' => 1000,
        ])->assertStatus(404)->assertJsonPath('error.code', 'CATEGORY_NOT_FOUND');

        $this->actingAs($owner)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => 1000,
            'occurred_on' => '2026-10-05',
            'category_id' => $theirCategory->id,
            'wallet_id' => wWallet($mine)->id,
        ])->assertStatus(404)->assertJsonPath('error.code', 'CATEGORY_NOT_FOUND');

        $this->actingAs($owner)->postJson('/api/wallets', [
            'name' => 'Milikku',
            'type' => 'cash',
            'opening_balance' => 0,
        ])->assertStatus(201);

        expect(Wallet::where('household_id', $theirs->id)->count())->toBe(1);
        expect($theirWallet->refresh()->name)->toBe($theirWallet->name);
    });
});
