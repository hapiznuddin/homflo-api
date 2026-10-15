<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Transaction;
use App\Models\User;
use App\Policies\BudgetPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function bgHousehold(User $owner, string $name = 'Rumah Budget', string $role = 'owner'): Household
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

function bgCategory(Household $household, string $name = 'Dapur'): Category
{
    return Category::factory()->for($household)->create(['name' => $name]);
}

function bgRecord(Household $household, Category $category, array $overrides = []): Budget
{
    return Budget::factory()
        ->for($household)
        ->for($category)
        ->create($overrides);
}

function bgPayload(array $overrides = []): array
{
    return array_merge([
        'year' => 2026,
        'month' => 10,
        'amount' => 500000,
    ], $overrides);
}

describe('Budgets API authentication', function () {
    test('guest cannot access protected budget endpoints', function () {
        $owner = User::factory()->create();
        $household = bgHousehold($owner);
        $budget = bgRecord($household, bgCategory($household));

        $this->getJson('/api/budgets')->assertStatus(401);
        $this->postJson('/api/budgets', bgPayload())->assertStatus(401);
        $this->getJson("/api/budgets/{$budget->id}")->assertStatus(401);
        $this->patchJson("/api/budgets/{$budget->id}", ['amount' => 1])->assertStatus(401);
        $this->deleteJson("/api/budgets/{$budget->id}")->assertStatus(401);
    });

    test('user without household receives household not found', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/budgets')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');

        $this->actingAs($user)->postJson('/api/budgets', array_merge(bgPayload(), [
            'category_id' => (string) Str::ulid(),
        ]))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');
    });
});

describe('Budgets API authorization and household isolation', function () {
    test('member lists only their own household budgets with period filters', function () {
        $owner = User::factory()->create();
        $mine = bgHousehold($owner, 'Milikku');
        $food = bgCategory($mine, 'Makan');
        $fare = bgCategory($mine, 'Transport');
        bgRecord($mine, $food, ['year' => 2026, 'month' => 10]);
        bgRecord($mine, $fare, ['year' => 2026, 'month' => 10]);
        bgRecord($mine, $food, ['year' => 2026, 'month' => 9]);

        $neighbor = User::factory()->create();
        $theirs = bgHousehold($neighbor, 'Tetangga');
        bgRecord($theirs, bgCategory($theirs, 'Rahasia'), ['year' => 2026, 'month' => 10]);

        $all = $this->actingAs($owner)->getJson('/api/budgets');

        $all->assertOk()->assertJsonPath('success', true);
        expect($all->json('data'))->toHaveCount(3);
        expect($all->getContent())->not->toContain('Rahasia');

        $october = $this->actingAs($owner)->getJson('/api/budgets?year=2026&month=10');

        expect($october->json('data'))->toHaveCount(2);

        $september = $this->actingAs($owner)->getJson('/api/budgets?year=2026&month=9');

        expect($september->json('data'))->toHaveCount(1);

        $year = $this->actingAs($owner)->getJson('/api/budgets?year=2026');

        expect($year->json('data'))->toHaveCount(3);

        $this->actingAs($owner)->getJson('/api/budgets?month=13')
            ->assertStatus(422)->assertJsonValidationErrors(['month']);

        $this->actingAs($owner)->getJson('/api/budgets?year=1999')
            ->assertStatus(422)->assertJsonValidationErrors(['year']);
    });

    test('cross-household budget access never leaks existence', function () {
        $owner = User::factory()->create();
        bgHousehold($owner);

        $stranger = User::factory()->create();
        $foreign = bgHousehold($stranger, 'Asing');
        $budget = bgRecord($foreign, bgCategory($foreign));

        $this->actingAs($owner)->getJson("/api/budgets/{$budget->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'BUDGET_NOT_FOUND');

        $this->actingAs($owner)->patchJson("/api/budgets/{$budget->id}", ['amount' => 1])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'BUDGET_NOT_FOUND');

        $this->actingAs($owner)->deleteJson("/api/budgets/{$budget->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'BUDGET_NOT_FOUND');

        expect(Budget::find($budget->id))->not->toBeNull();
        expect((string) Budget::find($budget->id)->amount)->not->toBe('1.00');
    });

    test('forged household_id in payload is ignored', function () {
        $owner = User::factory()->create();
        $mine = bgHousehold($owner);
        $category = bgCategory($mine);

        $stranger = User::factory()->create();
        $foreign = bgHousehold($stranger, 'Asing');

        $response = $this->actingAs($owner)->postJson('/api/budgets', array_merge(bgPayload(), [
            'category_id' => $category->id,
            'household_id' => $foreign->id,
        ]));

        $response->assertStatus(201);
        $response->assertJsonPath('data.household_id', $mine->id);
        expect(Budget::where('household_id', $foreign->id)->count())->toBe(0);

        $budget = bgRecord($mine, $category, ['year' => 2026, 'month' => 11]);

        $update = $this->actingAs($owner)->patchJson("/api/budgets/{$budget->id}", [
            'amount' => '600000.00',
            'household_id' => $foreign->id,
        ]);

        $update->assertOk();
        expect($budget->refresh()->household_id)->toBe($mine->id);
        expect($update->json('data'))->not->toHaveKeys(['user_id', 'role']);
    });

    test('foreign and missing categories surface identically as not found', function () {
        $owner = User::factory()->create();
        $mine = bgHousehold($owner);

        $stranger = User::factory()->create();
        $foreignHousehold = bgHousehold($stranger, 'Asing');
        $foreignCategory = bgCategory($foreignHousehold);

        $foreignResponse = $this->actingAs($owner)->postJson('/api/budgets', array_merge(bgPayload(), [
            'category_id' => $foreignCategory->id,
        ]));

        $foreignResponse
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'CATEGORY_NOT_FOUND');

        $missingResponse = $this->actingAs($owner)->postJson('/api/budgets', array_merge(bgPayload(), [
            'category_id' => (string) Str::ulid(),
        ]));

        $missingResponse
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'CATEGORY_NOT_FOUND');

        expect($missingResponse->json('error'))->toEqual($foreignResponse->json('error'));

        $budget = bgRecord($mine, bgCategory($mine));

        $this->actingAs($owner)->patchJson("/api/budgets/{$budget->id}", [
            'category_id' => $foreignCategory->id,
        ])->assertStatus(404)->assertJsonPath('error.code', 'CATEGORY_NOT_FOUND');
    });

    test('unverified member can read but cannot mutate targets', function () {
        $owner = User::factory()->unverified()->create();
        $household = bgHousehold($owner);
        $budget = bgRecord($household, bgCategory($household));

        $this->actingAs($owner)->getJson('/api/budgets')->assertOk();
        $this->actingAs($owner)->getJson("/api/budgets/{$budget->id}")->assertOk();

        $this->actingAs($owner)->postJson('/api/budgets', bgPayload())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->actingAs($owner)->patchJson("/api/budgets/{$budget->id}", ['amount' => 1])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->actingAs($owner)->deleteJson("/api/budgets/{$budget->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
    });

    test('member role can manage the shared targets', function () {
        $member = User::factory()->create();
        $household = bgHousehold($member, 'Keluarga', 'member');
        $category = bgCategory($household);

        $this->actingAs($member)->getJson('/api/budgets')->assertOk();

        $created = $this->actingAs($member)->postJson('/api/budgets', array_merge(bgPayload(), [
            'category_id' => $category->id,
        ]));
        $created->assertStatus(201);

        $budget = bgRecord($household, bgCategory($household, 'Lain'), ['year' => 2026, 'month' => 11]);

        $this->actingAs($member)->patchJson("/api/budgets/{$budget->id}", ['amount' => '700000.00'])
            ->assertOk();

        $this->actingAs($member)->deleteJson("/api/budgets/{$budget->id}")->assertOk();
    });
});

describe('Budgets API validation and persistence', function () {
    test('store validates required fields', function () {
        $owner = User::factory()->create();
        bgHousehold($owner);

        $this->actingAs($owner)->postJson('/api/budgets', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category_id', 'year', 'month', 'amount']);
    });

    test('store rejects malformed input', function () {
        $owner = User::factory()->create();
        $household = bgHousehold($owner);
        $category = bgCategory($household);
        $base = array_merge(bgPayload(), ['category_id' => $category->id]);

        $this->actingAs($owner)->postJson('/api/budgets', array_merge($base, ['category_id' => 'bukan-ulid']))
            ->assertStatus(422)->assertJsonValidationErrors(['category_id']);

        $this->actingAs($owner)->postJson('/api/budgets', array_merge($base, ['amount' => 'banyak']))
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->actingAs($owner)->postJson('/api/budgets', array_merge($base, ['amount' => -100]))
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->actingAs($owner)->postJson('/api/budgets', array_merge($base, ['amount' => 10000000000000]))
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->actingAs($owner)->postJson('/api/budgets', array_merge($base, ['month' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors(['month']);

        $this->actingAs($owner)->postJson('/api/budgets', array_merge($base, ['month' => 13]))
            ->assertStatus(422)->assertJsonValidationErrors(['month']);

        $this->actingAs($owner)->postJson('/api/budgets', array_merge($base, ['year' => 1999]))
            ->assertStatus(422)->assertJsonValidationErrors(['year']);

        $this->actingAs($owner)->postJson('/api/budgets', array_merge($base, ['year' => 2101]))
            ->assertStatus(422)->assertJsonValidationErrors(['year']);
    });

    test('store persists exact values with embedded category', function () {
        $owner = User::factory()->create();
        $household = bgHousehold($owner);
        $category = bgCategory($household);

        $response = $this->actingAs($owner)->postJson('/api/budgets', [
            'category_id' => $category->id,
            'year' => 2026,
            'month' => 10,
            'amount' => '1500000.50',
        ]);

        $response->assertStatus(201)->assertJsonPath('success', true);

        $data = $response->json('data');

        expect($data['year'])->toBe(2026);
        expect($data['month'])->toBe(10);
        expect($data['amount'])->toBe('1500000.50');
        expect($data['household_id'])->toBe($household->id);
        expect($data['category'])->toEqual([
            'id' => $category->id,
            'name' => 'Dapur',
            'icon' => $category->icon,
        ]);
        expect(array_keys($data))->toEqual([
            'id',
            'household_id',
            'category',
            'year',
            'month',
            'amount',
            'created_at',
            'updated_at',
        ]);

        expect($data)->not->toHaveKeys(['spent', 'spent_amount', 'remaining', 'remaining_amount', 'percent']);
    });

    test('duplicate targets are rejected per household, period, and category', function () {
        $owner = User::factory()->create();
        $household = bgHousehold($owner);
        $category = bgCategory($household);
        bgRecord($household, $category, ['year' => 2026, 'month' => 10]);

        $this->actingAs($owner)->postJson('/api/budgets', [
            'category_id' => $category->id,
            'year' => 2026,
            'month' => 10,
            'amount' => 999,
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'BUDGET_ALREADY_EXISTS');

        // Same category, another month is allowed.
        $this->actingAs($owner)->postJson('/api/budgets', [
            'category_id' => $category->id,
            'year' => 2026,
            'month' => 11,
            'amount' => 999,
        ])->assertStatus(201);

        // Same period, another category is allowed.
        $this->actingAs($owner)->postJson('/api/budgets', [
            'category_id' => bgCategory($household, 'Transport')->id,
            'year' => 2026,
            'month' => 10,
            'amount' => 999,
        ])->assertStatus(201);

        // Same everything in another household is allowed.
        $neighbor = User::factory()->create();
        $other = bgHousehold($neighbor, 'Tetangga');

        $this->actingAs($neighbor)->postJson('/api/budgets', [
            'category_id' => bgCategory($other)->id,
            'year' => 2026,
            'month' => 10,
            'amount' => 999,
        ])->assertStatus(201);
    });

    test('update applies partial changes without touching ownership', function () {
        $owner = User::factory()->create();
        $household = bgHousehold($owner);
        $category = bgCategory($household);
        $other = bgCategory($household, 'Transport');
        $budget = bgRecord($household, $category, ['year' => 2026, 'month' => 10, 'amount' => '500000.00']);

        $response = $this->actingAs($owner)->patchJson("/api/budgets/{$budget->id}", [
            'amount' => '200000.75',
            'category_id' => $other->id,
        ]);

        $response->assertOk();

        $fresh = $budget->refresh();

        expect((string) $fresh->amount)->toBe('200000.75');
        expect($fresh->category_id)->toBe($other->id);
        expect($fresh->year)->toBe(2026);
        expect($fresh->month)->toBe(10);
        expect($fresh->household_id)->toBe($household->id);
    });

    test('retargeting onto an occupied period is rejected', function () {
        $owner = User::factory()->create();
        $household = bgHousehold($owner);
        $category = bgCategory($household);
        $other = bgCategory($household, 'Transport');
        $budget = bgRecord($household, $category, ['year' => 2026, 'month' => 10]);
        bgRecord($household, $other, ['year' => 2026, 'month' => 10]);

        $this->actingAs($owner)->patchJson("/api/budgets/{$budget->id}", [
            'category_id' => $other->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BUDGET_ALREADY_EXISTS');

        expect($budget->refresh()->category_id)->toBe($category->id);

        // Retargeting onto a free period succeeds.
        $this->actingAs($owner)->patchJson("/api/budgets/{$budget->id}", [
            'month' => 11,
        ])->assertOk();

        expect($budget->refresh()->month)->toBe(11);
    });

    test('delete removes the target only', function () {
        $owner = User::factory()->create();
        $household = bgHousehold($owner);
        $category = bgCategory($household);
        $budget = bgRecord($household, $category);

        $transaction = Transaction::factory()
            ->for($household)
            ->for($category)
            ->create();

        $this->actingAs($owner)->deleteJson("/api/budgets/{$budget->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $budget->id);

        expect(Budget::find($budget->id))->toBeNull();
        expect(Transaction::find($transaction->id))->not->toBeNull();
        expect(Category::find($category->id))->not->toBeNull();
    });

    test('unknown ULIDs report not found without leaking', function () {
        $owner = User::factory()->create();
        bgHousehold($owner);
        $ghost = (string) Str::ulid();

        $this->actingAs($owner)->getJson("/api/budgets/{$ghost}")->assertStatus(404);
        $this->actingAs($owner)->patchJson("/api/budgets/{$ghost}", ['amount' => 1])->assertStatus(404);
        $this->actingAs($owner)->deleteJson("/api/budgets/{$ghost}")->assertStatus(404);
    });

    test('decimal precision round-trips exactly', function () {
        $owner = User::factory()->create();
        $household = bgHousehold($owner);

        $response = $this->actingAs($owner)->postJson('/api/budgets', [
            'category_id' => bgCategory($household)->id,
            'year' => 2026,
            'month' => 10,
            'amount' => '1234567.89',
        ]);

        $response->assertStatus(201);
        expect($response->json('data.amount'))->toBe('1234567.89');
    });

    test('listing runs a bounded number of queries', function () {
        $owner = User::factory()->create();
        $household = bgHousehold($owner);

        for ($i = 0; $i < 5; $i++) {
            bgRecord($household, bgCategory($household, "Kategori {$i}"), ['month' => $i + 1]);
        }

        DB::enableQueryLog();

        $this->actingAs($owner)->getJson('/api/budgets')->assertOk();

        expect(count(DB::getQueryLog()))->toBeLessThan(10);
    });
});

describe('Budgets protect category deletion', function () {
    test('category used by a budget still cannot be deleted', function () {
        $owner = User::factory()->create();
        $household = bgHousehold($owner);
        $category = bgCategory($household);
        $budget = bgRecord($household, $category);

        $this->actingAs($owner)->deleteJson("/api/categories/{$category->id}")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CATEGORY_IN_USE');

        expect(Category::find($category->id))->not->toBeNull();
        expect(Budget::find($budget->id))->not->toBeNull();
    });
});

describe('BudgetPolicy', function () {
    test('members manage their household targets, outsiders cannot', function () {
        $policy = new BudgetPolicy;

        $owner = User::factory()->create();
        $household = bgHousehold($owner);
        $budget = bgRecord($household, bgCategory($household));

        $outsider = User::factory()->create();
        $foreign = bgHousehold($outsider, 'Asing');
        $foreignBudget = bgRecord($foreign, bgCategory($foreign));

        expect($policy->viewAny($owner, $household))->toBeTrue();
        expect($policy->view($owner, $budget))->toBeTrue();
        expect($policy->create($owner, $household))->toBeTrue();
        expect($policy->update($owner, $budget))->toBeTrue();
        expect($policy->delete($owner, $budget))->toBeTrue();

        expect($policy->viewAny($outsider, $household))->toBeFalse();
        expect($policy->view($outsider, $budget))->toBeFalse();
        expect($policy->create($outsider, $household))->toBeFalse();
        expect($policy->update($outsider, $budget))->toBeFalse();
        expect($policy->delete($outsider, $budget))->toBeFalse();

        expect($policy->view($owner, $foreignBudget))->toBeFalse();
        expect($policy->update($owner, $foreignBudget))->toBeFalse();
        expect($policy->delete($owner, $foreignBudget))->toBeFalse();
    });
});
