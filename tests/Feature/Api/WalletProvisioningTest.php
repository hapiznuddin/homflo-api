<?php

use App\Exceptions\HouseholdAlreadyExistsException;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Models\Wallet;
use App\Repositories\Contracts\HouseholdMemberRepositoryInterface;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Services\Household\HouseholdService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

describe('default wallet provisioning', function () {
    test('a new household receives exactly one active cash wallet with a zero opening balance', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/households', ['name' => 'Rumah Hapiz']);

        $response->assertStatus(201);

        $householdId = $response->json('data.household.id');
        $wallets = Wallet::query()->where('household_id', $householdId)->get();

        expect($wallets)->toHaveCount(1);

        $wallet = $wallets->first();

        expect($wallet->name)->toBe('Cash');
        expect($wallet->type)->toBe(Wallet::TYPE_CASH);
        expect((string) $wallet->opening_balance)->toBe('0.00');
        expect($wallet->is_active)->toBeTrue();
        expect($wallet->household_id)->toBe($householdId);
    });

    test('the default wallet is visible through the wallet api with a zero derived balance', function () {
        $user = User::factory()->create();

        $householdId = $this->actingAs($user)
            ->postJson('/api/households', ['name' => 'Rumah Hapiz'])
            ->assertStatus(201)
            ->json('data.household.id');

        $wallet = Wallet::query()->where('household_id', $householdId)->sole();

        $response = $this->actingAs($user)->getJson('/api/wallets');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $wallet->id);
        $response->assertJsonPath('data.0.name', 'Cash');
        $response->assertJsonPath('data.0.type', 'cash');
        $response->assertJsonPath('data.0.opening_balance', '0.00');
        $response->assertJsonPath('data.0.balance', '0.00');
        $response->assertJsonPath('data.0.is_active', true);
        $response->assertJsonPath('data.0.household_id', $householdId);
    });

    test('the default wallet does not block additional wallets in the same household', function () {
        $user = User::factory()->create();

        $householdId = $this->actingAs($user)
            ->postJson('/api/households', ['name' => 'Rumah Hapiz'])
            ->assertStatus(201)
            ->json('data.household.id');

        $this->actingAs($user)->postJson('/api/wallets', [
            'name' => 'Bank',
            'type' => Wallet::TYPE_BANK,
            'opening_balance' => 0,
        ])->assertStatus(201);

        expect(Wallet::query()->where('household_id', $householdId)->count())->toBe(2);
        expect(Wallet::query()->where('household_id', $householdId)->where('name', 'Cash')->count())->toBe(1);
    });
});

describe('default wallet atomicity and duplicate prevention', function () {
    test('household creation rolls back when default wallet creation fails', function () {
        $user = User::factory()->create();

        $wallets = Mockery::mock(WalletRepositoryInterface::class);
        $wallets->shouldReceive('existsByNameForHousehold')->once()->andReturnFalse();
        $wallets->shouldReceive('create')->once()->andThrow(new RuntimeException('wallet failed'));

        app()->instance(WalletRepositoryInterface::class, $wallets);

        $service = app(HouseholdService::class);

        expect(fn () => $service->createHouseholdForUser($user, 'Rollback Home'))
            ->toThrow(RuntimeException::class, 'wallet failed');

        expect(Household::query()->count())->toBe(0);
        expect(HouseholdMember::query()->count())->toBe(0);
        expect(Wallet::query()->count())->toBe(0);
        expect($user->refresh()->active_household_id)->toBeNull();
    });

    test('retrying household creation does not create a second default wallet', function () {
        $user = User::factory()->create();

        $householdId = $this->actingAs($user)
            ->postJson('/api/households', ['name' => 'Rumah Hapiz'])
            ->assertStatus(201)
            ->json('data.household.id');

        $this->actingAs($user)->postJson('/api/households', ['name' => 'Rumah Kedua'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'HOUSEHOLD_ALREADY_EXISTS');

        expect(Household::query()->where('created_by', $user->id)->count())->toBe(1);
        expect(Wallet::query()->where('household_id', $householdId)->count())->toBe(1);
        expect(Wallet::query()->count())->toBe(1);
    });

    test('a concurrent household creation re-check prevents a second household and wallet', function () {
        $user = User::factory()->create();

        $existingMembership = new HouseholdMember([
            'household_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        // Simulate the race: the pre-flight check sees no membership, then a
        // competing request commits one before this request acquires its lock.
        $members = Mockery::mock(HouseholdMemberRepositoryInterface::class);
        $members->shouldReceive('findPrimaryMembershipForUser')
            ->twice()
            ->andReturn(null, $existingMembership);

        app()->instance(HouseholdMemberRepositoryInterface::class, $members);

        $service = app(HouseholdService::class);

        expect(fn () => $service->createHouseholdForUser($user, 'Concurrent Home'))
            ->toThrow(HouseholdAlreadyExistsException::class);

        expect(Household::query()->count())->toBe(0);
        expect(Wallet::query()->count())->toBe(0);
    });

    test('the database rejects a duplicate Cash wallet for the same household', function () {
        $user = User::factory()->create();

        $householdId = $this->actingAs($user)
            ->postJson('/api/households', ['name' => 'Rumah Hapiz'])
            ->assertStatus(201)
            ->json('data.household.id');

        expect(Wallet::query()->where('household_id', $householdId)->count())->toBe(1);

        // The unique (household_id, name) index is the final duplicate guard.
        // Run this last: PostgreSQL aborts the surrounding transaction after
        // a constraint violation.
        expect(fn () => Wallet::query()->create([
            'household_id' => $householdId,
            'name' => 'Cash',
            'type' => Wallet::TYPE_BANK,
            'opening_balance' => 0,
            'is_active' => true,
        ]))->toThrow(QueryException::class);
    });
});

describe('default wallet stability and isolation', function () {
    test('repeated login does not create or duplicate the default wallet', function () {
        $user = User::factory()->create();

        $householdId = $this->actingAs($user)
            ->postJson('/api/households', ['name' => 'Rumah Hapiz'])
            ->assertStatus(201)
            ->json('data.household.id');

        expect(Wallet::query()->where('household_id', $householdId)->count())->toBe(1);

        // Clear the test authentication so each attempt is a real login.
        $this->postJson('/logout')->assertNoContent();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/login', [
                'email' => $user->email,
                'password' => 'password',
            ])->assertOk();

            $this->postJson('/logout')->assertNoContent();
        }

        $this->actingAs($user)->getJson('/api/me')->assertOk();

        expect(Wallet::query()->where('household_id', $householdId)->count())->toBe(1);
        expect(Wallet::query()->count())->toBe(1);
    });

    test('existing households without wallets are never backfilled by login or normal requests', function () {
        $user = User::factory()->create();

        $household = Household::create([
            'name' => 'Rumah Lama',
            'created_by' => $user->id,
        ]);

        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        expect(Wallet::query()->count())->toBe(0);

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->actingAs($user)->getJson('/api/me')->assertOk();
        $this->actingAs($user)->getJson('/api/wallets')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        expect(Wallet::query()->where('household_id', $household->id)->count())->toBe(0);
        expect(Wallet::query()->count())->toBe(0);
    });

    test('existing wallet balances and opening balances stay unchanged by login and reads', function () {
        $user = User::factory()->create();

        $household = Household::create([
            'name' => 'Rumah Lama',
            'created_by' => $user->id,
        ]);

        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $wallet = Wallet::factory()->for($household)->create([
            'name' => 'Kas Lama',
            'type' => Wallet::TYPE_CASH,
            'opening_balance' => '50000.00',
            'is_active' => true,
        ]);

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->actingAs($user)->getJson('/api/me')->assertOk();
        $this->actingAs($user)->getJson('/api/wallets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.balance', '50000.00');

        $wallet->refresh();

        expect((string) $wallet->opening_balance)->toBe('50000.00');
        expect($wallet->name)->toBe('Kas Lama');
        expect(Wallet::query()->where('household_id', $household->id)->count())->toBe(1);
    });

    test('default wallets stay isolated between households', function () {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();

        $householdAId = $this->actingAs($ownerA)
            ->postJson('/api/households', ['name' => 'Rumah A'])
            ->assertStatus(201)
            ->json('data.household.id');

        $householdBId = $this->actingAs($ownerB)
            ->postJson('/api/households', ['name' => 'Rumah B'])
            ->assertStatus(201)
            ->json('data.household.id');

        $walletA = Wallet::query()->where('household_id', $householdAId)->sole();
        $walletB = Wallet::query()->where('household_id', $householdBId)->sole();

        expect($walletA->id)->not->toBe($walletB->id);

        $this->actingAs($ownerA)->getJson('/api/wallets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $walletA->id);

        $this->actingAs($ownerA)->getJson("/api/wallets/{$walletB->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'WALLET_NOT_FOUND');
    });

    test('default wallet authorization follows the existing owner and member contract', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $householdId = $this->actingAs($owner)
            ->postJson('/api/households', ['name' => 'Keluarga'])
            ->assertStatus(201)
            ->json('data.household.id');

        HouseholdMember::create([
            'household_id' => $householdId,
            'user_id' => $member->id,
            'role' => 'member',
        ]);

        $wallet = Wallet::query()->where('household_id', $householdId)->sole();

        $this->actingAs($member)->getJson('/api/wallets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $wallet->id);

        $this->actingAs($member)->getJson("/api/wallets/{$wallet->id}")->assertOk();
        $this->actingAs($member)->getJson("/api/wallets/{$wallet->id}/adjustments")->assertOk();

        $this->actingAs($member)->patchJson("/api/wallets/{$wallet->id}", ['name' => 'Kas'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'WALLET_NOT_FOUND');

        $this->actingAs($member)->postJson("/api/wallets/{$wallet->id}/deactivate")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'WALLET_NOT_FOUND');

        $this->actingAs($member)->deleteJson("/api/wallets/{$wallet->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'WALLET_NOT_FOUND');

        $this->actingAs($owner)->patchJson("/api/wallets/{$wallet->id}", ['name' => 'Kas'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Kas');
    });
});
