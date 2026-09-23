<?php

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Repositories\Contracts\HouseholdMemberRepositoryInterface;
use App\Repositories\Contracts\HouseholdRepositoryInterface;
use App\Repositories\HouseholdRepository;
use App\Services\Household\HouseholdService;
use Illuminate\Database\Eloquent\Collection;

describe('POST /api/households', function () {
    test('returns 401 for unauthenticated request', function () {
        $response = $this->postJson('/api/households', ['name' => 'Rumah Hapiz']);

        $response->assertStatus(401);
    });

    test('authenticated user without household can create household', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/households', ['name' => 'Rumah Hapiz']);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.household.name', 'Rumah Hapiz');
        $response->assertJsonPath('data.household.role', 'owner');
        $response->assertJsonPath('data.onboarding.required', false);
    });

    test('created household exists in database', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/households', ['name' => 'Rumah Hapiz']);

        $householdId = $response->json('data.household.id');

        $this->assertDatabaseHas('households', [
            'id' => $householdId,
            'name' => 'Rumah Hapiz',
            'created_by' => $user->id,
        ]);
    });

    test('created household_members record exists for authenticated user with owner role', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/households', ['name' => 'Rumah Hapiz']);

        $householdId = $response->json('data.household.id');

        $this->assertDatabaseHas('household_members', [
            'household_id' => $householdId,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
    });

    test('client cannot choose user_id', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/households', [
            'name' => 'Rumah Hapiz',
            'user_id' => $otherUser->id,
        ]);

        $response->assertStatus(201);

        $householdId = $response->json('data.household.id');

        $this->assertDatabaseHas('household_members', [
            'household_id' => $householdId,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $this->assertDatabaseMissing('household_members', [
            'household_id' => $householdId,
            'user_id' => $otherUser->id,
        ]);
    });

    test('client cannot choose household_id', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/households', [
            'name' => 'Rumah Hapiz',
            'household_id' => '01K6W5Z5Z5Z5Z5Z5Z5Z5Z5Z5Z5',
        ]);

        $response->assertStatus(201);

        expect($response->json('data.household.id'))->not->toBe('01K6W5Z5Z5Z5Z5Z5Z5Z5Z5Z5Z5');
    });

    test('client cannot choose role', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/households', [
            'name' => 'Rumah Hapiz',
            'role' => 'member',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.household.role', 'owner');

        $this->assertDatabaseMissing('household_members', [
            'user_id' => $user->id,
            'role' => 'member',
        ]);
    });

    test('authenticated user who already has a household cannot create another household', function () {
        $user = User::factory()->create();

        $household = Household::create(['name' => 'First Home', 'created_by' => $user->id]);
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user)->postJson('/api/households', ['name' => 'Second Home']);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'HOUSEHOLD_ALREADY_EXISTS');

        $this->assertDatabaseMissing('households', ['name' => 'Second Home']);
    });

    test('missing household name returns validation error', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/households', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    });

    test('non-string household name returns validation error', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/households', ['name' => 12345]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    });

    test('transaction rolls back household creation when membership creation fails', function () {
        $user = User::factory()->create();

        $failingMembers = new class implements HouseholdMemberRepositoryInterface
        {
            public function findPrimaryMembershipForUser(User $user): ?HouseholdMember
            {
                return null;
            }

            public function create(array $data): HouseholdMember
            {
                throw new RuntimeException('membership failed');
            }

            public function listForHousehold(Household $household): Collection
            {
                throw new RuntimeException('not implemented in test double');
            }

            public function findByIdForHousehold(Household $household, string $memberId): ?HouseholdMember
            {
                throw new RuntimeException('not implemented in test double');
            }

            public function findByHouseholdAndUser(Household $household, User $user): ?HouseholdMember
            {
                throw new RuntimeException('not implemented in test double');
            }

            public function updateRole(HouseholdMember $membership, string $role): HouseholdMember
            {
                throw new RuntimeException('not implemented in test double');
            }

            public function delete(HouseholdMember $membership): void
            {
                throw new RuntimeException('not implemented in test double');
            }

            public function countOwnersForHousehold(Household $household): int
            {
                throw new RuntimeException('not implemented in test double');
            }
        };

        app()->instance(HouseholdMemberRepositoryInterface::class, $failingMembers);

        $service = app(HouseholdService::class);

        expect(fn () => $service->createHouseholdForUser($user, 'Rollback Home'))
            ->toThrow(RuntimeException::class);

        expect(Household::query()->count())->toBe(0);
        expect(HouseholdMember::query()->count())->toBe(0);
    });

    test('successful creation produces onboarding required false on GET me afterwards', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/households', ['name' => 'Rumah Hapiz'])
            ->assertStatus(201);

        $response = $this->actingAs($user)->getJson('/api/me');

        $response->assertStatus(200);
        $response->assertJsonPath('data.household.name', 'Rumah Hapiz');
        $response->assertJsonPath('data.household.role', 'owner');
        $response->assertJsonPath('data.onboarding.required', false);
    });

    test('response does not expose password or tokens', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/households', ['name' => 'Rumah Hapiz']);

        $response->assertStatus(201);
        $response->assertJsonMissing(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'token']);
    });

    test('HouseholdRepositoryInterface is bound in the container', function () {
        expect(app(HouseholdRepositoryInterface::class))
            ->toBeInstanceOf(HouseholdRepository::class);
    });
});
