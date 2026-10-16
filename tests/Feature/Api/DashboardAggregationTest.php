<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

function daHousehold(User $owner, string $name = 'Rumah Agregat', string $role = 'owner'): Household
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

function daCategory(Household $household, string $type, string $name = 'Kategori Agregat'): Category
{
    return Category::factory()->for($household)->create([
        'name' => $name.' '.Str::random(4),
        'type' => $type,
    ]);
}

function daWallet(Household $household, array $overrides = []): Wallet
{
    return Wallet::factory()->for($household)->create(array_merge([
        'opening_balance' => '0.00',
    ], $overrides));
}

function daTx(Household $household, array $overrides = []): Transaction
{
    return Transaction::factory()->for($household)->create(array_merge([
        'occurred_on' => '2026-10-05',
    ], $overrides));
}

function daFinancial(TestResponse $response): array
{
    return $response->json('data.financial');
}

afterEach(function () {
    Carbon::setTestNow();
});

beforeEach(function () {
    // Freeze "today" mid-October so future-dated October rows are
    // deterministically beyond the Asia/Jakarta business date
    // (2026-10-15 12:00 UTC == 19:00 WIB).
    Carbon::setTestNow(Carbon::create(2026, 10, 15, 12, 0, 0, 'UTC'));
});

describe('Dashboard income and net cash flow aggregation', function () {
    test('income only yields positive net cash flow and surplus', function () {
        $owner = User::factory()->create();
        $household = daHousehold($owner);
        $category = daCategory($household, Category::TYPE_INCOME);
        daTx($household, ['type' => 'income', 'category_id' => $category->id, 'amount' => '100000.50']);

        $financial = daFinancial($this->actingAs($owner)->getJson('/api/dashboard?year=2026&month=10')->assertOk());

        expect($financial['income'])->toBe('100000.50');
        expect($financial['expense'])->toBe('0.00');
        expect($financial['net_cash_flow'])->toBe('100000.50');
        expect($financial['cash_flow_status'])->toBe('surplus');
    });

    test('expense only yields negative net cash flow and defisit', function () {
        $owner = User::factory()->create();
        $household = daHousehold($owner);
        $category = daCategory($household, Category::TYPE_EXPENSE);
        daTx($household, ['type' => 'expense', 'category_id' => $category->id, 'amount' => '50250.25']);

        $financial = daFinancial($this->actingAs($owner)->getJson('/api/dashboard?year=2026&month=10')->assertOk());

        expect($financial['income'])->toBe('0.00');
        expect($financial['expense'])->toBe('50250.25');
        expect($financial['net_cash_flow'])->toBe('-50250.25');
        expect($financial['cash_flow_status'])->toBe('defisit');
    });

    test('income and expense in the same period net exactly', function () {
        $owner = User::factory()->create();
        $household = daHousehold($owner);
        daTx($household, ['type' => 'income', 'category_id' => daCategory($household, Category::TYPE_INCOME)->id, 'amount' => '250000.75']);
        daTx($household, ['type' => 'expense', 'category_id' => daCategory($household, Category::TYPE_EXPENSE)->id, 'amount' => '50250.25']);

        $financial = daFinancial($this->actingAs($owner)->getJson('/api/dashboard?year=2026&month=10')->assertOk());

        expect($financial['income'])->toBe('250000.75');
        expect($financial['expense'])->toBe('50250.25');
        expect($financial['net_cash_flow'])->toBe('199750.50');
        expect($financial['cash_flow_status'])->toBe('surplus');
    });

    test('zero net cash flow reports seimbang', function () {
        $owner = User::factory()->create();
        $household = daHousehold($owner);
        daTx($household, ['type' => 'income', 'amount' => '50000.00']);
        daTx($household, ['type' => 'expense', 'amount' => '50000.00']);

        $financial = daFinancial($this->actingAs($owner)->getJson('/api/dashboard?year=2026&month=10')->assertOk());

        expect($financial['net_cash_flow'])->toBe('0.00');
        expect($financial['cash_flow_status'])->toBe('seimbang');
    });

    test('transfers never affect income, expense, or net cash flow', function () {
        $owner = User::factory()->create();
        $household = daHousehold($owner);
        $source = daWallet($household, ['name' => 'Kas', 'opening_balance' => '1000000.00']);
        $destination = daWallet($household, ['name' => 'Bank', 'opening_balance' => '0.00']);
        daTx($household, [
            'type' => 'transfer',
            'category_id' => null,
            'wallet_id' => $source->id,
            'destination_wallet_id' => $destination->id,
            'amount' => '250000.00',
        ]);

        $financial = daFinancial($this->actingAs($owner)->getJson('/api/dashboard?year=2026&month=10')->assertOk());

        expect($financial['income'])->toBe('0.00');
        expect($financial['expense'])->toBe('0.00');
        expect($financial['net_cash_flow'])->toBe('0.00');
        expect($financial['cash_flow_status'])->toBe('seimbang');
    });

    test('opening balance and adjustments are not operating income', function () {
        $owner = User::factory()->create();
        $household = daHousehold($owner);
        $wallet = daWallet($household, ['name' => 'Kas', 'opening_balance' => '100000.00']);

        $this->actingAs($owner)->postJson("/api/wallets/{$wallet->id}/adjustments", [
            'amount' => '7777.77',
            'reason' => 'koreksi opname',
        ])->assertStatus(201);

        $financial = daFinancial($this->actingAs($owner)->getJson('/api/dashboard?year=2026&month=10')->assertOk());

        expect($financial['income'])->toBe('0.00');
        expect($financial['expense'])->toBe('0.00');
        expect($financial['net_cash_flow'])->toBe('0.00');
    });

    test('other households never leak into aggregates', function () {
        $owner = User::factory()->create();
        $mine = daHousehold($owner);

        $stranger = User::factory()->create();
        $theirs = daHousehold($stranger, 'Tetangga');
        daTx($theirs, ['type' => 'income', 'amount' => '9000000.00']);
        daTx($theirs, ['type' => 'expense', 'amount' => '8000000.00']);

        $financial = daFinancial($this->actingAs($owner)->getJson('/api/dashboard?year=2026&month=10')->assertOk());

        expect($financial['income'])->toBe('0.00');
        expect($financial['expense'])->toBe('0.00');
        expect($financial['net_cash_flow'])->toBe('0.00');
        expect(Transaction::where('household_id', $mine->id)->count())->toBe(0);
    });

    test('members with read access can read aggregates without being owner', function () {
        $member = User::factory()->create();
        $household = daHousehold($member, 'Keluarga', 'member');
        daTx($household, ['type' => 'income', 'amount' => '120000.00']);
        daTx($household, ['type' => 'expense', 'amount' => '45000.00']);

        $financial = daFinancial($this->actingAs($member)->getJson('/api/dashboard?year=2026&month=10')->assertOk());

        expect($financial['income'])->toBe('120000.00');
        expect($financial['expense'])->toBe('45000.00');
        expect($financial['net_cash_flow'])->toBe('75000.00');
        expect($financial['cash_flow_status'])->toBe('surplus');
    });

    test('transactions outside the period are excluded', function () {
        $owner = User::factory()->create();
        $household = daHousehold($owner);
        daTx($household, ['type' => 'income', 'amount' => '10000.00', 'occurred_on' => '2026-09-30']);
        daTx($household, ['type' => 'income', 'amount' => '20000.00', 'occurred_on' => '2026-11-01']);
        daTx($household, ['type' => 'expense', 'amount' => '30000.00', 'occurred_on' => '2026-09-15']);
        daTx($household, ['type' => 'expense', 'amount' => '40000.00', 'occurred_on' => '2026-11-20']);
        daTx($household, ['type' => 'income', 'amount' => '50000.00', 'occurred_on' => '2026-10-10']);

        $financial = daFinancial($this->actingAs($owner)->getJson('/api/dashboard?year=2026&month=10')->assertOk());

        expect($financial['income'])->toBe('50000.00');
        expect($financial['expense'])->toBe('0.00');
        expect($financial['net_cash_flow'])->toBe('50000.00');
    });

    test('future-dated income is excluded like future-dated expense', function () {
        $owner = User::factory()->create();
        $household = daHousehold($owner);
        daTx($household, ['type' => 'income', 'amount' => '99999.99', 'occurred_on' => '2026-10-20']);
        daTx($household, ['type' => 'expense', 'amount' => '88888.88', 'occurred_on' => '2026-10-25']);
        daTx($household, ['type' => 'income', 'amount' => '10000.00', 'occurred_on' => '2026-10-15']);
        daTx($household, ['type' => 'expense', 'amount' => '4000.00', 'occurred_on' => '2026-10-01']);

        $financial = daFinancial($this->actingAs($owner)->getJson('/api/dashboard?year=2026&month=10')->assertOk());

        expect($financial['income'])->toBe('10000.00');
        expect($financial['expense'])->toBe('4000.00');
        expect($financial['net_cash_flow'])->toBe('6000.00');
        expect($financial['cash_flow_status'])->toBe('surplus');
    });

    test('fully future periods aggregate to zero strings', function () {
        $owner = User::factory()->create();
        $household = daHousehold($owner);
        daTx($household, ['type' => 'income', 'amount' => '10000.00', 'occurred_on' => '2026-11-05']);

        $financial = daFinancial($this->actingAs($owner)->getJson('/api/dashboard?year=2026&month=11')->assertOk());

        expect($financial['income'])->toBe('0.00');
        expect($financial['expense'])->toBe('0.00');
        expect($financial['net_cash_flow'])->toBe('0.00');
        expect($financial['cash_flow_status'])->toBe('seimbang');
    });

    test('month boundaries are inclusive on both ends', function () {
        $owner = User::factory()->create();
        $household = daHousehold($owner);
        daTx($household, ['type' => 'income', 'amount' => '111.11', 'occurred_on' => '2026-10-01']);
        daTx($household, ['type' => 'income', 'amount' => '222.22', 'occurred_on' => '2026-10-15']);
        daTx($household, ['type' => 'expense', 'amount' => '33.33', 'occurred_on' => '2026-10-31']);
        daTx($household, ['type' => 'expense', 'amount' => '0.01', 'occurred_on' => '2026-10-01']);

        // Today is the 15th, so the 31st rows sit beyond the business date.
        $financial = daFinancial($this->actingAs($owner)->getJson('/api/dashboard?year=2026&month=10')->assertOk());

        expect($financial['income'])->toBe('333.33');
        expect($financial['expense'])->toBe('0.01');
        expect($financial['net_cash_flow'])->toBe('333.32');
    });

    test('decimal precision holds for fractions and schema-max values', function () {
        $owner = User::factory()->create();
        $household = daHousehold($owner);
        daTx($household, ['type' => 'income', 'amount' => '9876543210987.65']);
        daTx($household, ['type' => 'expense', 'amount' => '0.05']);

        $financial = daFinancial($this->actingAs($owner)->getJson('/api/dashboard?year=2026&month=10')->assertOk());

        expect($financial['income'])->toBe('9876543210987.65');
        expect($financial['expense'])->toBe('0.05');
        expect($financial['net_cash_flow'])->toBe('9876543210987.60');
        expect($financial['cash_flow_status'])->toBe('surplus');
    });

    test('dashboard expense matches the budgets household total for the same period', function () {
        $owner = User::factory()->create();
        $household = daHousehold($owner);
        $category = daCategory($household, Category::TYPE_EXPENSE);
        Budget::factory()->for($household)->for($category)->create([
            'year' => 2026,
            'month' => 10,
            'amount' => '500000.00',
        ]);
        daTx($household, ['type' => 'expense', 'category_id' => $category->id, 'amount' => '125000.75']);

        $dashboard = $this->actingAs($owner)->getJson('/api/dashboard?year=2026&month=10')->assertOk();
        $budgets = $this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10')->assertOk();

        expect($dashboard->json('data.financial.expense'))->toBe('125000.75');
        expect($budgets->json('meta.household.actual_spending'))->toBe('125000.75');
        expect($dashboard->json('data.financial.income'))->toBe('0.00');
        expect($dashboard->json('data.financial.net_cash_flow'))->toBe('-125000.75');
        expect($dashboard->json('data.financial.cash_flow_status'))->toBe('defisit');
    });

    test('dashboard envelope keeps its unrelated shape', function () {
        $owner = User::factory()->create();
        $household = daHousehold($owner);
        daTx($household, ['type' => 'income', 'amount' => '1000.00']);

        $response = $this->actingAs($owner)->getJson('/api/dashboard?year=2026&month=10')->assertOk();

        expect($response->json('data.period'))->toEqual(['year' => 2026, 'month' => 10, 'key' => '2026-10']);
        expect($response->json('data.household.id'))->toBe($household->id);
        expect($response->json('data.financial.income_change_percent'))->toBe(0);
        expect($response->json('data.financial.budget_usage_percent'))->toBe(0);
        expect($response->json('data.budget'))->toEqual([]);
        expect($response->json('data.meta.unsupported_domains'))->toEqual(['transactions', 'budgets', 'bills', 'maintenance']);
    });
});
