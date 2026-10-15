<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Testing\TestResponse;

function spendingHousehold(User $owner, string $name = 'Rumah Spending'): Household
{
    $household = Household::create([
        'name' => $name,
        'created_by' => $owner->id,
    ]);

    HouseholdMember::create([
        'household_id' => $household->id,
        'user_id' => $owner->id,
        'role' => 'owner',
    ]);

    return $household->refresh();
}

function spendingCategory(Household $household, string $name = 'Dapur'): Category
{
    return Category::factory()->create([
        'household_id' => $household->id,
        'name' => $name,
    ]);
}

function spendingBudget(Household $household, Category $category, array $overrides = []): Budget
{
    return Budget::factory()->create(array_merge([
        'household_id' => $household->id,
        'category_id' => $category->id,
        'year' => 2026,
        'month' => 10,
        'amount' => '500000.00',
    ], $overrides));
}

function spendingTx(Household $household, array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'household_id' => $household->id,
        'category_id' => null,
        'type' => Transaction::TYPE_EXPENSE,
        'amount' => '100000.00',
        'occurred_on' => '2026-10-05',
    ], $overrides));
}

function spendingItem(TestResponse $response, string $id): array
{
    foreach ($response->json('data') as $row) {
        if ($row['id'] === $id) {
            return $row;
        }
    }

    throw new RuntimeException("Budget {$id} missing from response.");
}

afterEach(function () {
    Carbon::setTestNow();
});

beforeEach(function () {
    // Freeze after all October fixtures but before November, so October
    // transactions are settled past and November is still future.
    // Tests needing another instant override with setTestNow themselves.
    Carbon::setTestNow(Carbon::create(2026, 11, 15, 0, 0, 0, 'UTC'));
});

describe('Actual spending dasar', function () {
    test('satu expense dalam bulan budget dihitung', function () {
        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        $budget = spendingBudget($household, $category);
        spendingTx($household, ['category_id' => $category->id, 'amount' => '150000.50']);

        $response = $this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10');

        $response->assertOk();
        $item = spendingItem($response, $budget->id);

        expect($item['actual_spending'])->toBe('150000.50');
        expect($item['remaining'])->toBe('349999.50');
        expect((float) $item['usage_percentage'])->toBe(30.0);
        expect($item['usage_status'])->toBe('normal');
    });

    test('income dalam periode yang sama tidak dihitung', function () {
        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        $budget = spendingBudget($household, $category);
        spendingTx($household, ['category_id' => $category->id, 'type' => Transaction::TYPE_INCOME, 'amount' => '9000000.00']);

        $item = spendingItem($this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10'), $budget->id);

        expect($item['actual_spending'])->toBe('0.00');
        expect($item['remaining'])->toBe('500000.00');
    });

    test('expense kategori lain tidak masuk budget yang dihitung', function () {
        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        $other = spendingCategory($household, 'Transport');
        $budget = spendingBudget($household, $category);
        spendingTx($household, ['category_id' => $other->id, 'amount' => '200000.00']);

        $item = spendingItem($this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10'), $budget->id);

        expect($item['actual_spending'])->toBe('0.00');
    });

    test('tanpa kategori masuk total household tetapi bukan kategori', function () {
        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        $budget = spendingBudget($household, $category);
        spendingTx($household, ['category_id' => $category->id, 'amount' => '500000.00']);
        spendingTx($household, ['category_id' => null, 'amount' => '100000.00']);

        $response = $this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10');

        $response->assertOk();
        $item = spendingItem($response, $budget->id);

        expect($item['actual_spending'])->toBe('500000.00');
        expect($response->json('meta.household.actual_spending'))->toBe('600000.00');
        expect($response->json('meta.period'))->toEqual(['year' => 2026, 'month' => 10]);
    });

    test('spending adalah jumlah tepat transaksi yang memenuhi syarat', function () {
        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        $budget = spendingBudget($household, $category, ['amount' => '1000000.00']);

        foreach (['100000.10', '200000.20', '300000.30'] as $amount) {
            spendingTx($household, ['category_id' => $category->id, 'amount' => $amount]);
        }

        $item = spendingItem($this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10'), $budget->id);

        expect($item['actual_spending'])->toBe('600000.60');
        expect($item['remaining'])->toBe('399999.40');
    });

    test('remaining boleh negatif saat over budget', function () {
        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        $budget = spendingBudget($household, $category, ['amount' => '100000.00']);
        spendingTx($household, ['category_id' => $category->id, 'amount' => '250000.00']);

        $item = spendingItem($this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10'), $budget->id);

        expect($item['actual_spending'])->toBe('250000.00');
        expect($item['remaining'])->toBe('-150000.00');
        expect((float) $item['usage_percentage'])->toBe(250.0);
        expect($item['usage_status'])->toBe('normal');
    });

    test('budget nol memakai status khusus non-numerik', function () {
        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        $budget = spendingBudget($household, $category, ['amount' => '0.00']);
        spendingTx($household, ['category_id' => $category->id, 'amount' => '50000.00']);

        $item = spendingItem($this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10'), $budget->id);

        expect($item['actual_spending'])->toBe('50000.00');
        expect($item['remaining'])->toBe('-50000.00');
        expect($item['usage_percentage'])->toBeNull();
        expect($item['usage_status'])->toBe('no_budget');
    });

    test('budget positif memakai persentase numerik dan status normal', function () {
        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        $budget = spendingBudget($household, $category, ['amount' => '300000.00']);
        spendingTx($household, ['category_id' => $category->id, 'amount' => '100000.00']);

        $item = spendingItem($this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10'), $budget->id);

        expect($item['usage_percentage'])->toBe(33.33);
        expect($item['usage_status'])->toBe('normal');
    });
});

describe('Actual spending periode dan timezone', function () {
    test('transaksi hari pertama dan terakhir bulan dihitung', function () {
        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        $budget = spendingBudget($household, $category, ['amount' => '1000000.00']);
        spendingTx($household, ['category_id' => $category->id, 'amount' => '10000.00', 'occurred_on' => '2026-10-01']);
        spendingTx($household, ['category_id' => $category->id, 'amount' => '20000.00', 'occurred_on' => '2026-10-31']);

        $item = spendingItem($this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10'), $budget->id);

        expect($item['actual_spending'])->toBe('30000.00');
    });

    test('transaksi sehari sebelum dan sesudah periode tidak dihitung', function () {
        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        $budget = spendingBudget($household, $category);
        spendingTx($household, ['category_id' => $category->id, 'amount' => '10000.00', 'occurred_on' => '2026-09-30']);
        spendingTx($household, ['category_id' => $category->id, 'amount' => '20000.00', 'occurred_on' => '2026-11-01']);

        $item = spendingItem($this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10'), $budget->id);

        expect($item['actual_spending'])->toBe('0.00');
    });

    test('transaksi masa depan WIB tidak dihitung sampai tanggalnya tiba', function () {
        Carbon::setTestNow(Carbon::create(2026, 10, 4, 12, 0, 0, 'UTC'));

        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        $budget = spendingBudget($household, $category);
        spendingTx($household, ['category_id' => $category->id, 'amount' => '10000.00', 'occurred_on' => '2026-10-05']);

        $item = spendingItem($this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10'), $budget->id);

        expect($item['actual_spending'])->toBe('0.00');
    });

    test('tanggal acuan memakai Asia/Jakarta, bukan UTC', function () {
        // 2026-10-04 17:30 UTC masih tanggal 4 di UTC tetapi sudah
        // tanggal 5 di WIB — transaksi occurred_on tanggal 5 ikut dihitung.
        Carbon::setTestNow(Carbon::create(2026, 10, 4, 17, 30, 0, 'UTC'));

        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        $budget = spendingBudget($household, $category);
        spendingTx($household, ['category_id' => $category->id, 'amount' => '10000.00', 'occurred_on' => '2026-10-05']);

        $item = spendingItem($this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10'), $budget->id);

        expect($item['actual_spending'])->toBe('10000.00');
    });

    test('transaksi tepat pada tanggal acuan bisnis dihitung', function () {
        Carbon::setTestNow(Carbon::create(2026, 10, 5, 0, 0, 0, 'Asia/Jakarta'));

        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        $budget = spendingBudget($household, $category);
        spendingTx($household, ['category_id' => $category->id, 'amount' => '10000.00', 'occurred_on' => '2026-10-05']);

        $item = spendingItem($this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10'), $budget->id);

        expect($item['actual_spending'])->toBe('10000.00');
    });

    test('periode bulan mendatang menghasilkan nol', function () {
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 0, 0, 0, 'Asia/Jakarta'));

        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        $budget = spendingBudget($household, $category);

        $item = spendingItem($this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10'), $budget->id);

        expect($item['actual_spending'])->toBe('0.00');
        expect($item['remaining'])->toBe('500000.00');
    });

    test('satu tanggal acuan dipakai konsisten dalam satu request', function () {
        Carbon::setTestNow(Carbon::create(2026, 10, 5, 0, 0, 0, 'Asia/Jakarta'));

        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $first = spendingCategory($household, 'Satu');
        $second = spendingCategory($household, 'Dua');
        $firstBudget = spendingBudget($household, $first);
        $secondBudget = spendingBudget($household, $second);
        spendingTx($household, ['category_id' => $first->id, 'amount' => '10000.00', 'occurred_on' => '2026-10-05']);
        spendingTx($household, ['category_id' => $second->id, 'amount' => '20000.00', 'occurred_on' => '2026-10-05']);

        $response = $this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10');

        $response->assertOk();
        expect(spendingItem($response, $firstBudget->id)['actual_spending'])->toBe('10000.00');
        expect(spendingItem($response, $secondBudget->id)['actual_spending'])->toBe('20000.00');
        expect($response->json('meta.household.actual_spending'))->toBe('30000.00');
    });
});

describe('Actual spending dan perubahan data', function () {
    test('edit amount memperbarui agregasi', function () {
        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        $budget = spendingBudget($household, $category);
        $transaction = spendingTx($household, ['category_id' => $category->id, 'amount' => '10000.00']);

        $this->actingAs($owner)->patchJson("/api/transactions/{$transaction->id}", ['amount' => '40000.00'])
            ->assertOk();

        $item = spendingItem($this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10'), $budget->id);

        expect($item['actual_spending'])->toBe('40000.00');
    });

    test('edit type menjadi income mengeluarkan dari agregasi', function () {
        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        $budget = spendingBudget($household, $category);
        $transaction = spendingTx($household, ['category_id' => $category->id, 'amount' => '10000.00']);

        $this->actingAs($owner)->patchJson("/api/transactions/{$transaction->id}", ['type' => 'income'])
            ->assertOk();

        $item = spendingItem($this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10'), $budget->id);

        expect($item['actual_spending'])->toBe('0.00');
    });

    test('edit occurred_on ke bulan lain memindahkan agregasi', function () {
        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        $budget = spendingBudget($household, $category);
        $transaction = spendingTx($household, ['category_id' => $category->id, 'amount' => '10000.00', 'occurred_on' => '2026-10-05']);

        $this->actingAs($owner)->patchJson("/api/transactions/{$transaction->id}", ['occurred_on' => '2026-09-05'])
            ->assertOk();

        $october = spendingItem($this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10'), $budget->id);

        expect($october['actual_spending'])->toBe('0.00');

        $september = $this->actingAs($owner)->getJson('/api/budgets?year=2026&month=9');

        expect($september->json('meta.household.actual_spending'))->toBe('10000.00');
    });

    test('edit category memindahkan kontribusi', function () {
        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $from = spendingCategory($household, 'Dari');
        $to = spendingCategory($household, 'Ke');
        $fromBudget = spendingBudget($household, $from);
        $toBudget = spendingBudget($household, $to);
        $transaction = spendingTx($household, ['category_id' => $from->id, 'amount' => '10000.00']);

        $this->actingAs($owner)->patchJson("/api/transactions/{$transaction->id}", ['category_id' => $to->id])
            ->assertOk();

        $response = $this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10');

        expect(spendingItem($response, $fromBudget->id)['actual_spending'])->toBe('0.00');
        expect(spendingItem($response, $toBudget->id)['actual_spending'])->toBe('10000.00');
    });

    test('delete transaksi mengeluarkannya dari agregasi aktif', function () {
        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        $budget = spendingBudget($household, $category);
        $transaction = spendingTx($household, ['category_id' => $category->id, 'amount' => '10000.00']);

        $this->actingAs($owner)->deleteJson("/api/transactions/{$transaction->id}")->assertOk();

        $item = spendingItem($this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10'), $budget->id);

        expect($item['actual_spending'])->toBe('0.00');
    });

    test('kategori menjadi null mengeluarkan dari kategori tetapi bukan household', function () {
        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        $budget = spendingBudget($household, $category);
        $transaction = spendingTx($household, ['category_id' => $category->id, 'amount' => '10000.00']);

        $this->actingAs($owner)->patchJson("/api/transactions/{$transaction->id}", ['category_id' => null])
            ->assertOk();

        $response = $this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10');

        expect(spendingItem($response, $budget->id)['actual_spending'])->toBe('0.00');
        expect($response->json('meta.household.actual_spending'))->toBe('10000.00');
    });
});

describe('Actual spending household isolation', function () {
    test('transaksi household lain tidak masuk total maupun budget', function () {
        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        $budget = spendingBudget($household, $category);
        spendingTx($household, ['category_id' => $category->id, 'amount' => '10000.00']);

        $stranger = User::factory()->create();
        $foreign = spendingHousehold($stranger, 'Asing');
        $foreignCategory = spendingCategory($foreign);
        spendingTx($foreign, ['category_id' => $foreignCategory->id, 'amount' => '9000000.00']);

        $response = $this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10');

        expect(spendingItem($response, $budget->id)['actual_spending'])->toBe('10000.00');
        expect($response->json('meta.household.actual_spending'))->toBe('10000.00');
    });

    test('budget tidak dapat memakai kategori household lain', function () {
        $owner = User::factory()->create();
        spendingHousehold($owner);

        $stranger = User::factory()->create();
        $foreign = spendingHousehold($stranger, 'Asing');
        $foreignCategory = spendingCategory($foreign);

        $this->actingAs($owner)->postJson('/api/budgets', [
            'category_id' => $foreignCategory->id,
            'year' => 2026,
            'month' => 10,
            'amount' => 100000,
        ])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'CATEGORY_NOT_FOUND');
    });

    test('payload household palsu tidak mengubah scope agregasi', function () {
        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        $budget = spendingBudget($household, $category);
        spendingTx($household, ['category_id' => $category->id, 'amount' => '10000.00']);

        $stranger = User::factory()->create();
        $foreign = spendingHousehold($stranger, 'Asing');

        $response = $this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10&household_id='.$foreign->id);

        $response->assertOk();
        expect(spendingItem($response, $budget->id)['actual_spending'])->toBe('10000.00');
        expect($response->json('meta.household.actual_spending'))->toBe('10000.00');
    });

    test('pengguna tanpa membership tetap ditolak', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/budgets?year=2026&month=10')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');
    });

    test('resource lintas-household tetap 404', function () {
        $owner = User::factory()->create();
        spendingHousehold($owner);

        $stranger = User::factory()->create();
        $foreign = spendingHousehold($stranger, 'Asing');
        $foreignBudget = spendingBudget($foreign, spendingCategory($foreign));

        $this->actingAs($owner)->getJson("/api/budgets/{$foreignBudget->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'BUDGET_NOT_FOUND');
    });
});

describe('Actual spending kontrak dan regresi', function () {
    test('endpoint mempertahankan field dan envelope lama', function () {
        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        $budget = spendingBudget($household, $category);

        $response = $this->actingAs($owner)->getJson("/api/budgets/{$budget->id}");

        $response->assertOk()->assertJsonPath('success', true);

        $data = $response->json('data');

        foreach (['id', 'household_id', 'category', 'year', 'month', 'amount', 'created_at', 'updated_at'] as $field) {
            expect($data)->toHaveKey($field);
        }

        expect($data['category'])->toEqual([
            'id' => $category->id,
            'name' => 'Dapur',
            'icon' => $category->icon,
        ]);
    });

    test('amount tetap decimal string eksak', function () {
        $owner = User::factory()->create();
        $household = spendingHousehold($owner);

        $response = $this->actingAs($owner)->postJson('/api/budgets', [
            'category_id' => spendingCategory($household)->id,
            'year' => 2026,
            'month' => 10,
            'amount' => '1234567.89',
        ]);

        $response->assertStatus(201);
        expect($response->json('data.amount'))->toBe('1234567.89');
    });

    test('actual_spending dan remaining konsisten formatnya', function () {
        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        $budget = spendingBudget($household, $category, ['amount' => '100000.00']);
        spendingTx($household, ['category_id' => $category->id, 'amount' => '33333.33']);

        $item = $this->actingAs($owner)->getJson("/api/budgets/{$budget->id}")->json('data');

        expect($item['actual_spending'])->toMatch('/^-?\d+\.\d{2}$/');
        expect($item['remaining'])->toMatch('/^-?\d+\.\d{2}$/');
    });

    test('usage_percentage tidak pernah NaN atau Infinity', function () {
        $owner = User::factory()->create();
        $household = spendingHousehold($owner);

        $zero = spendingBudget($household, spendingCategory($household, 'Nol'), ['amount' => '0.00']);
        $positiveCategory = spendingCategory($household, 'Positif');
        $positive = spendingBudget($household, $positiveCategory, ['amount' => '100000.00']);
        spendingTx($household, ['category_id' => $positiveCategory->id, 'amount' => '10000.00', 'occurred_on' => '2026-10-05']);

        $response = $this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10');

        $raw = $response->getContent();

        expect($raw)->not->toContain('NaN');
        expect($raw)->not->toContain('Infinity');

        $zeroItem = spendingItem($response, $zero->id);
        $positiveItem = spendingItem($response, $positive->id);

        expect($zeroItem['usage_percentage'])->toBeNull();
        expect($zeroItem['usage_status'])->toBe('no_budget');
        expect((float) $positiveItem['usage_percentage'])->toBe(10.0);
        expect($positiveItem['usage_status'])->toBe('normal');
    });

    test('filter year/month mengikuti kontrak existing', function () {
        $owner = User::factory()->create();
        $household = spendingHousehold($owner);
        $category = spendingCategory($household);
        spendingBudget($household, $category, ['year' => 2026, 'month' => 10]);
        spendingBudget($household, $category, ['year' => 2026, 'month' => 11]);

        $october = $this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10');

        expect($october->json('data'))->toHaveCount(1);
        expect($october->json('meta.period'))->toEqual(['year' => 2026, 'month' => 10]);

        $all = $this->actingAs($owner)->getJson('/api/budgets');

        expect($all->json('data'))->toHaveCount(2);
        expect($all->json())->not->toHaveKey('meta');
    });
});
