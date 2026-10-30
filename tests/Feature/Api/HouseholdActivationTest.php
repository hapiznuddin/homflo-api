<?php

use App\Models\Category;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\HouseholdMember;
use App\Models\User;

function membershipHome(User $owner, string $name = 'Rumah', ?string $createdAt = null): Household
{
    $household = Household::create([
        'name' => $name,
        'created_by' => $owner->id,
    ]);

    $membership = HouseholdMember::create([
        'household_id' => $household->id,
        'user_id' => $owner->id,
        'role' => 'owner',
    ]);

    if ($createdAt !== null) {
        // Keep the ordering deterministic in fixtures: rapid inserts can share
        // the same second.
        $membership->forceFill(['created_at' => $createdAt])->save();
    }

    return $household->refresh();
}

function addMembership(User $user, Household $household, string $role = 'member', ?string $createdAt = null): HouseholdMember
{
    $membership = HouseholdMember::create([
        'household_id' => $household->id,
        'user_id' => $user->id,
        'role' => $role,
    ]);

    if ($createdAt !== null) {
        $membership->forceFill(['created_at' => $createdAt])->save();
    }

    return $membership->refresh();
}

describe('Active household resolution', function () {
    test('GET /me exposes every membership in deterministic order and marks the active one', function () {
        $user = User::factory()->create();
        $older = membershipHome($user, 'Rumah Lama', '2026-01-01 00:00:00');
        $newer = Household::create(['name' => 'Rumah Baru', 'created_by' => $user->id]);
        addMembership($user, $newer, 'member', '2026-02-01 00:00:00');

        $user->active_household_id = $newer->id;
        $user->save();

        $response = $this->actingAs($user)->getJson('/api/me');

        $response->assertOk();
        $response->assertJsonPath('data.household.id', $newer->id);
        $response->assertJsonPath('data.household.role', 'member');
        $response->assertJsonCount(2, 'data.households');
        $response->assertJsonPath('data.households.0.id', $older->id);
        $response->assertJsonPath('data.households.1.id', $newer->id);
        $response->assertJsonPath('data.onboarding.required', false);
    });

    test('a null pointer falls back to the oldest membership deterministically', function () {
        $user = User::factory()->create();
        $older = membershipHome($user, 'Rumah Lama', '2026-01-01 00:00:00');
        $newer = Household::create(['name' => 'Rumah Baru', 'created_by' => $user->id]);
        addMembership($user, $newer, 'member', '2026-03-01 00:00:00');

        expect($user->refresh()->active_household_id)->toBeNull();

        $this->actingAs($user)->getJson('/api/me')
            ->assertJsonPath('data.household.id', $older->id);
    });

    test('a stale pointer that no longer matches a membership falls back instead of leaking', function () {
        $user = User::factory()->create();
        $home = membershipHome($user);
        $foreignOwner = User::factory()->create();
        $foreign = membershipHome($foreignOwner, 'Rumah Orang Lain');

        $user->active_household_id = $foreign->id;
        $user->save();

        $this->actingAs($user)->getJson('/api/me')
            ->assertJsonPath('data.household.id', $home->id);
    });

    test('user without memberships still sees onboarding required', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/me')
            ->assertJsonPath('data.household', null)
            ->assertJsonCount(0, 'data.households')
            ->assertJsonPath('data.onboarding.required', true);
    });
});

describe('POST /api/households/{household}/activate', function () {
    test('guest cannot activate a household', function () {
        $owner = User::factory()->create();
        $household = membershipHome($owner);

        $this->postJson("/api/households/{$household->id}/activate")->assertUnauthorized();
    });

    test('member can switch the active household and every resource follows it', function () {
        $user = User::factory()->create();
        $homeA = membershipHome($user, 'Rumah A', '2026-01-01 00:00:00');
        $homeB = Household::create(['name' => 'Rumah B', 'created_by' => $user->id]);
        addMembership($user, $homeB, 'member', '2026-02-01 00:00:00');

        Category::create(['household_id' => $homeB->id, 'name' => 'Kategori B', 'icon' => 'i-lucide-tag']);
        Category::create(['household_id' => $homeA->id, 'name' => 'Kategori A', 'icon' => 'i-lucide-tag']);

        $before = $this->actingAs($user)->getJson('/api/categories');
        $before->assertOk();
        expect($before->getContent())->toContain('Kategori A')
            ->and($before->getContent())->not->toContain('Kategori B');

        $response = $this->actingAs($user)->postJson("/api/households/{$homeB->id}/activate");

        $response->assertOk();
        $response->assertJsonPath('data.household.id', $homeB->id);
        $response->assertJsonPath('data.household.role', 'member');
        $response->assertJsonCount(2, 'data.households');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'active_household_id' => $homeB->id]);

        $this->actingAs($user)->getJson('/api/me')
            ->assertJsonPath('data.household.id', $homeB->id);

        $after = $this->actingAs($user)->getJson('/api/categories');
        $after->assertOk();
        expect($after->getContent())->toContain('Kategori B')
            ->and($after->getContent())->not->toContain('Kategori A');
    });

    test('activation is idempotent', function () {
        $owner = User::factory()->create();
        $household = membershipHome($owner);

        $this->actingAs($owner)->postJson("/api/households/{$household->id}/activate")->assertOk();
        $this->actingAs($owner)->postJson("/api/households/{$household->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.household.id', $household->id);
    });

    test('activating a household the user does not belong to is masked as 404', function () {
        $user = User::factory()->create();
        $home = membershipHome($user);
        $foreignOwner = User::factory()->create();
        $foreign = membershipHome($foreignOwner, 'Rumah Lain');

        $response = $this->actingAs($user)->postJson("/api/households/{$foreign->id}/activate");

        $response->assertNotFound();
        $response->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');

        expect($user->refresh()->active_household_id)->toBeNull();
        expect($home->id)->not->toBe($foreign->id);
    });

    test('activating a nonexistent household is masked as 404', function () {
        $user = User::factory()->create();
        membershipHome($user);

        $response = $this->actingAs($user)
            ->postJson('/api/households/01J0000000000000000000001/activate');

        $response->assertNotFound();
        $response->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');
    });

    test('cross-household records stay isolated after switching', function () {
        $user = User::factory()->create();
        membershipHome($user, 'Rumah A', '2026-01-01 00:00:00');
        $homeB = Household::create(['name' => 'Rumah B', 'created_by' => $user->id]);
        addMembership($user, $homeB, 'member', '2026-02-01 00:00:00');

        $categoryB = Category::create([
            'household_id' => $homeB->id,
            'name' => 'Kategori B',
            'icon' => 'i-lucide-tag',
        ]);

        // Still on A: B's record must be masked as 404.
        $this->actingAs($user)->getJson("/api/categories/{$categoryB->id}")->assertNotFound();

        $this->actingAs($user)->postJson("/api/households/{$homeB->id}/activate")->assertOk();

        $this->actingAs($user)->getJson("/api/categories/{$categoryB->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $categoryB->id);
    });

    test('accepting an invitation activates the invited household', function () {
        $invited = User::factory()->create();
        $ownHome = membershipHome($invited, 'Rumah Sendiri', '2026-01-01 00:00:00');

        $owner = User::factory()->create();
        $otherHome = Household::create(['name' => 'Rumah Ibu', 'created_by' => $owner->id]);
        addMembership($owner, $otherHome, 'owner');

        $invitation = HouseholdInvitation::create([
            'household_id' => $otherHome->id,
            'invited_by' => $owner->id,
            'email' => $invited->email,
            'token_hash' => hash('sha256', 'activation-token'),
            'status' => HouseholdInvitation::STATUS_PENDING,
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->actingAs($invited)->postJson(
            "/api/invitations/{$invitation->id}/accept",
            ['token' => 'activation-token']
        );

        $response->assertStatus(201);

        expect($invited->refresh()->active_household_id)->toBe($otherHome->id);

        $this->actingAs($invited)->getJson('/api/me')
            ->assertJsonPath('data.household.id', $otherHome->id)
            ->assertJsonPath('data.household.role', 'member')
            ->assertJsonPath('data.households.0.id', $ownHome->id);
    });

    test('removing a member clears their pointer and falls back to the oldest remaining membership', function () {
        $owner = User::factory()->create();
        $ownerHome = membershipHome($owner, 'Rumah Owner', '2026-01-01 00:00:00');

        $member = User::factory()->create();
        membershipHome($member, 'Rumah Lain', '2026-01-01 00:00:00');
        $shared = addMembership($member, $ownerHome, 'member', '2026-02-01 00:00:00');
        $olderMembership = HouseholdMember::query()
            ->where('user_id', $member->id)
            ->where('household_id', '!=', $ownerHome->id)
            ->first();

        $member->active_household_id = $ownerHome->id;
        $member->save();

        $this->actingAs($owner)
            ->deleteJson("/api/households/{$ownerHome->id}/members/{$shared->id}")
            ->assertOk();

        expect($member->refresh()->active_household_id)->toBe($olderMembership->household_id);

        $this->actingAs($member)->getJson('/api/me')
            ->assertJsonPath('data.household.id', $olderMembership->household_id);
    });

    test('removing the last membership returns the user to onboarding', function () {
        $owner = User::factory()->create();
        $sharedHome = membershipHome($owner);
        $member = User::factory()->create();
        $shared = addMembership($member, $sharedHome, 'member');

        $member->active_household_id = $sharedHome->id;
        $member->save();

        $this->actingAs($owner)
            ->deleteJson("/api/households/{$sharedHome->id}/members/{$shared->id}")
            ->assertOk();

        expect($member->refresh()->active_household_id)->toBeNull();

        $this->actingAs($member)->getJson('/api/me')
            ->assertJsonPath('data.household', null)
            ->assertJsonPath('data.onboarding.required', true);
    });
});
