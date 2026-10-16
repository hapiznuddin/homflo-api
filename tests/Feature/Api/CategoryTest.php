<?php

use App\Models\Bill;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function catHousehold(User $owner, string $name = 'Rumah Kategori', string $role = 'owner'): Household
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

function catPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Dapur',
        'icon' => 'i-lucide-cooking-pot',
        'type' => 'expense',
    ], $overrides);
}

describe('Categories API authentication', function () {
    test('guest cannot access protected category endpoints', function () {
        $owner = User::factory()->create();
        $household = catHousehold($owner);
        $category = Category::factory()->for($household)->create();

        $this->getJson('/api/categories')->assertStatus(401);
        $this->postJson('/api/categories', catPayload())->assertStatus(401);
        $this->getJson("/api/categories/{$category->id}")->assertStatus(401);
        $this->patchJson("/api/categories/{$category->id}", ['name' => 'x'])->assertStatus(401);
        $this->deleteJson("/api/categories/{$category->id}")->assertStatus(401);
    });

    test('user without household receives household not found', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/categories')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');

        $this->actingAs($user)->postJson('/api/categories', catPayload())
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');
    });
});

describe('Categories API authorization and household isolation', function () {
    test('member lists only their own household categories in name order', function () {
        $owner = User::factory()->create();
        $mine = catHousehold($owner, 'Milikku');
        Category::factory()->for($mine)->create(['name' => 'Zebra']);
        Category::factory()->for($mine)->create(['name' => 'Apel']);

        $neighbor = User::factory()->create();
        $theirs = catHousehold($neighbor, 'Tetangga');
        Category::factory()->for($theirs)->create(['name' => 'Rahasia tetangga']);

        $response = $this->actingAs($owner)->getJson('/api/categories');

        $response->assertOk()->assertJsonPath('success', true);

        $names = array_column($response->json('data'), 'name');

        expect($names)->toEqual(['Apel', 'Zebra']);
        expect($response->getContent())->not->toContain('Rahasia tetangga');
    });

    test('cross-household category access never leaks existence', function () {
        $owner = User::factory()->create();
        catHousehold($owner);

        $stranger = User::factory()->create();
        $foreign = catHousehold($stranger, 'Asing');
        $category = Category::factory()->for($foreign)->create();

        $this->actingAs($owner)->getJson("/api/categories/{$category->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'CATEGORY_NOT_FOUND');

        $this->actingAs($owner)->patchJson("/api/categories/{$category->id}", ['name' => 'bajak'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'CATEGORY_NOT_FOUND');

        $this->actingAs($owner)->deleteJson("/api/categories/{$category->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'CATEGORY_NOT_FOUND');

        expect(Category::find($category->id))->not->toBeNull();
        expect(Category::find($category->id)->name)->not->toBe('bajak');
    });

    test('forged household_id in payload is ignored', function () {
        $owner = User::factory()->create();
        $mine = catHousehold($owner);

        $stranger = User::factory()->create();
        $foreign = catHousehold($stranger, 'Asing');

        $response = $this->actingAs($owner)->postJson('/api/categories', catPayload([
            'household_id' => $foreign->id,
        ]));

        $response->assertStatus(201);
        $response->assertJsonPath('data.household_id', $mine->id);
        expect(Category::where('household_id', $foreign->id)->count())->toBe(0);

        $category = Category::factory()->for($mine)->create();

        $update = $this->actingAs($owner)->patchJson("/api/categories/{$category->id}", [
            'name' => 'diubah',
            'household_id' => $foreign->id,
        ]);

        $update->assertOk();
        expect($category->refresh()->household_id)->toBe($mine->id);
    });

    test('unverified member can read but cannot mutate the catalogue', function () {
        $owner = User::factory()->unverified()->create();
        $household = catHousehold($owner);
        $category = Category::factory()->for($household)->create();

        $this->actingAs($owner)->getJson('/api/categories')->assertOk();
        $this->actingAs($owner)->getJson("/api/categories/{$category->id}")->assertOk();

        $this->actingAs($owner)->postJson('/api/categories', catPayload())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->actingAs($owner)->patchJson("/api/categories/{$category->id}", ['name' => 'x'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->actingAs($owner)->deleteJson("/api/categories/{$category->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
    });

    test('member role reads but cannot mutate the shared catalogue', function () {
        $member = User::factory()->create();
        $household = catHousehold($member, 'Keluarga', 'member');

        $this->actingAs($member)->getJson('/api/categories')->assertOk();

        $this->actingAs($member)->postJson('/api/categories', catPayload(['name' => 'Jajan']))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');

        $category = Category::factory()->for($household)->create();

        $this->actingAs($member)->getJson("/api/categories/{$category->id}")->assertOk();

        $this->actingAs($member)->patchJson("/api/categories/{$category->id}", ['name' => 'Oleh member'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'CATEGORY_NOT_FOUND');

        $this->actingAs($member)->deleteJson("/api/categories/{$category->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'CATEGORY_NOT_FOUND');
    });
});

describe('Categories API validation and persistence', function () {
    test('store validates required fields', function () {
        $owner = User::factory()->create();
        catHousehold($owner);

        $this->actingAs($owner)->postJson('/api/categories', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'type']);
    });

    test('store rejects malformed input', function () {
        $owner = User::factory()->create();
        catHousehold($owner);

        $this->actingAs($owner)->postJson('/api/categories', catPayload(['name' => 123]))
            ->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->actingAs($owner)->postJson('/api/categories', catPayload(['name' => str_repeat('n', 256)]))
            ->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->actingAs($owner)->postJson('/api/categories', catPayload(['icon' => 123]))
            ->assertStatus(422)->assertJsonValidationErrors(['icon']);

        $this->actingAs($owner)->postJson('/api/categories', catPayload(['type' => 'profit']))
            ->assertStatus(422)->assertJsonValidationErrors(['type']);
    });

    test('store persists with explicit presenter and icon defaults to null', function () {
        $owner = User::factory()->create();
        $household = catHousehold($owner);

        $response = $this->actingAs($owner)->postJson('/api/categories', ['name' => 'Dapur', 'type' => 'expense']);

        $response->assertStatus(201)->assertJsonPath('success', true);

        $data = $response->json('data');

        expect($data['name'])->toBe('Dapur');
        expect($data['icon'])->toBeNull();
        expect($data['type'])->toBe('expense');
        expect($data['household_id'])->toBe($household->id);
        expect(array_keys($data))->toEqual([
            'id',
            'household_id',
            'name',
            'icon',
            'type',
            'created_at',
            'updated_at',
        ]);
    });

    test('duplicate names are rejected per household but allowed across households', function () {
        $owner = User::factory()->create();
        $mine = catHousehold($owner);
        Category::factory()->for($mine)->create(['name' => 'Dapur']);

        $this->actingAs($owner)->postJson('/api/categories', catPayload(['name' => 'Dapur']))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'CATEGORY_ALREADY_EXISTS');

        $neighbor = User::factory()->create();
        catHousehold($neighbor, 'Tetangga');

        $this->actingAs($neighbor)->postJson('/api/categories', catPayload(['name' => 'Dapur']))
            ->assertStatus(201);
    });

    test('name comparison is case-sensitive on both database engines', function () {
        $owner = User::factory()->create();
        $household = catHousehold($owner);
        Category::factory()->for($household)->create(['name' => 'Dapur']);

        // No silent normalization: a different case is a different name.
        $this->actingAs($owner)->postJson('/api/categories', catPayload(['name' => 'DAPUR']))
            ->assertStatus(201);

        expect(Category::forHousehold($household)->count())->toBe(2);
    });

    test('update renames, changes icon, detaches icon, and guards duplicates', function () {
        $owner = User::factory()->create();
        $household = catHousehold($owner);
        $category = Category::factory()->for($household)->create(['name' => 'Dapur', 'icon' => 'i-a']);
        Category::factory()->for($household)->create(['name' => 'Transport']);

        $response = $this->actingAs($owner)->patchJson("/api/categories/{$category->id}", [
            'name' => 'Dapur Bersih',
            'icon' => 'i-b',
        ]);

        $response->assertOk();

        $fresh = $category->refresh();

        expect($fresh->name)->toBe('Dapur Bersih');
        expect($fresh->icon)->toBe('i-b');
        expect($fresh->household_id)->toBe($household->id);

        $this->actingAs($owner)->patchJson("/api/categories/{$category->id}", ['name' => 'Transport'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CATEGORY_ALREADY_EXISTS');

        // Renaming to the current name is a valid no-op.
        $this->actingAs($owner)->patchJson("/api/categories/{$category->id}", ['name' => 'Dapur Bersih'])
            ->assertOk();

        $this->actingAs($owner)->patchJson("/api/categories/{$category->id}", ['icon' => null])
            ->assertOk();

        expect($category->refresh()->icon)->toBeNull();
    });

    test('unknown ULIDs report not found without leaking', function () {
        $owner = User::factory()->create();
        catHousehold($owner);
        $ghost = (string) Str::ulid();

        $this->actingAs($owner)->getJson("/api/categories/{$ghost}")->assertStatus(404);
        $this->actingAs($owner)->patchJson("/api/categories/{$ghost}", ['name' => 'x'])->assertStatus(404);
        $this->actingAs($owner)->deleteJson("/api/categories/{$ghost}")->assertStatus(404);
    });

    test('listing runs a bounded number of queries', function () {
        $owner = User::factory()->create();
        $household = catHousehold($owner);
        Category::factory()->for($household)->count(5)->create();

        DB::enableQueryLog();

        $this->actingAs($owner)->getJson('/api/categories')->assertOk();

        expect(count(DB::getQueryLog()))->toBeLessThan(10);
    });
});

describe('Categories API delete rules', function () {
    test('deleting a bare category removes only the row', function () {
        $owner = User::factory()->create();
        $household = catHousehold($owner);
        $category = Category::factory()->for($household)->create();

        $this->actingAs($owner)->deleteJson("/api/categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $category->id);

        expect(Category::find($category->id))->toBeNull();
    });

    test('deleting a category detaches transactions instead of destroying them', function () {
        $owner = User::factory()->create();
        $household = catHousehold($owner);
        $category = Category::factory()->for($household)->create();

        $transaction = Transaction::factory()
            ->for($household)
            ->for($category)
            ->create();

        $this->actingAs($owner)->deleteJson("/api/categories/{$category->id}")->assertOk();

        expect(Category::find($category->id))->toBeNull();

        $fresh = $transaction->refresh();

        expect($fresh)->not->toBeNull();
        expect($fresh->category_id)->toBeNull();
    });

    test('deleting a category detaches bills instead of destroying them', function () {
        $owner = User::factory()->create();
        $household = catHousehold($owner);
        $category = Category::factory()->for($household)->create();

        $bill = Bill::factory()
            ->for($household)
            ->for($category)
            ->create();

        $this->actingAs($owner)->deleteJson("/api/categories/{$category->id}")->assertOk();

        $fresh = $bill->refresh();

        expect($fresh)->not->toBeNull();
        expect($fresh->category_id)->toBeNull();
    });

    test('deleting a category used by a budget is rejected without data loss', function () {
        $owner = User::factory()->create();
        $household = catHousehold($owner);
        $category = Category::factory()->for($household)->create();

        $budget = Budget::factory()
            ->for($household)
            ->for($category)
            ->create();

        $this->actingAs($owner)->deleteJson("/api/categories/{$category->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'CATEGORY_IN_USE');

        expect(Category::find($category->id))->not->toBeNull();
        expect(Budget::find($budget->id))->not->toBeNull();
    });
});
