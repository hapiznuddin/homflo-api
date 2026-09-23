<?php

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;

function makeHomfloHousehold(User $owner, string $name = 'Test Home'): Household
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

function addHomfloMember(Household $household, string $role = 'member'): User
{
    $user = User::factory()->create();

    HouseholdMember::create([
        'household_id' => $household->id,
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

describe('Household members', function () {
    test('owner can list members', function () {
        $owner = User::factory()->create();
        $household = makeHomfloHousehold($owner);
        $member = addHomfloMember($household);

        $response = $this->actingAs($owner)->getJson("/api/households/{$household->id}/members");

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonFragment(['user_id' => $owner->id, 'role' => 'owner']);
        $response->assertJsonFragment(['user_id' => $member->id, 'role' => 'member']);
    });

    test('member can list members', function () {
        $owner = User::factory()->create();
        $household = makeHomfloHousehold($owner);
        $member = addHomfloMember($household);

        $response = $this->actingAs($member)->getJson("/api/households/{$household->id}/members");

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(2, 'data');
    });

    test('guest cannot list members', function () {
        $owner = User::factory()->create();
        $household = makeHomfloHousehold($owner);

        $this->getJson("/api/households/{$household->id}/members")->assertUnauthorized();
    });

    test('user from another household cannot list members', function () {
        $owner = User::factory()->create();
        $household = makeHomfloHousehold($owner);
        $outsider = User::factory()->create();
        makeHomfloHousehold($outsider, 'Other Home');

        $response = $this->actingAs($outsider)->getJson("/api/households/{$household->id}/members");

        $response->assertNotFound();
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');
    });

    test('member list does not expose password hash or tokens', function () {
        $owner = User::factory()->create();
        $household = makeHomfloHousehold($owner);

        $response = $this->actingAs($owner)->getJson("/api/households/{$household->id}/members");

        $response->assertOk();
        $response->assertJsonMissing(['password']);
        expect($response->getContent())->not->toContain($owner->getAuthPassword())
            ->and($response->getContent())->not->toContain('plainTextToken');
    });

    test('owner can demote another owner to member when an owner remains', function () {
        $owner = User::factory()->create();
        $household = makeHomfloHousehold($owner);
        $secondOwner = addHomfloMember($household, 'owner');
        $membership = HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('user_id', $secondOwner->id)
            ->firstOrFail();

        $response = $this->actingAs($owner)->patchJson(
            "/api/households/{$household->id}/members/{$membership->id}",
            ['role' => 'member']
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.role', 'member');

        $this->assertDatabaseHas('household_members', [
            'id' => $membership->id,
            'role' => 'member',
        ]);
    });

    test('owner can promote a member to owner', function () {
        $owner = User::factory()->create();
        $household = makeHomfloHousehold($owner);
        $member = addHomfloMember($household);
        $membership = HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('user_id', $member->id)
            ->firstOrFail();

        $response = $this->actingAs($owner)->patchJson(
            "/api/households/{$household->id}/members/{$membership->id}",
            ['role' => 'owner']
        );

        $response->assertOk();
        $response->assertJsonPath('data.role', 'owner');
    });

    test('member cannot change a role', function () {
        $owner = User::factory()->create();
        $household = makeHomfloHousehold($owner);
        $member = addHomfloMember($household);
        $membership = HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('user_id', $member->id)
            ->firstOrFail();

        $response = $this->actingAs($member)->patchJson(
            "/api/households/{$household->id}/members/{$membership->id}",
            ['role' => 'owner']
        );

        $response->assertForbidden();
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    });

    test('cross-household user cannot change a role', function () {
        $owner = User::factory()->create();
        $household = makeHomfloHousehold($owner);
        $member = addHomfloMember($household);
        $membership = HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('user_id', $member->id)
            ->firstOrFail();
        $outsider = User::factory()->create();
        makeHomfloHousehold($outsider, 'Other Home');

        $response = $this->actingAs($outsider)->patchJson(
            "/api/households/{$household->id}/members/{$membership->id}",
            ['role' => 'owner']
        );

        $response->assertNotFound();
        $response->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');
    });

    test('invalid role is rejected', function () {
        $owner = User::factory()->create();
        $household = makeHomfloHousehold($owner);
        $member = addHomfloMember($household);
        $membership = HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('user_id', $member->id)
            ->firstOrFail();

        $response = $this->actingAs($owner)->patchJson(
            "/api/households/{$household->id}/members/{$membership->id}",
            ['role' => 'admin']
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['role']);
    });

    test('payload user_id cannot hijack role change target', function () {
        $owner = User::factory()->create();
        $household = makeHomfloHousehold($owner);
        $member = addHomfloMember($household);
        $other = User::factory()->create();
        $membership = HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('user_id', $member->id)
            ->firstOrFail();

        $response = $this->actingAs($owner)->patchJson(
            "/api/households/{$household->id}/members/{$membership->id}",
            ['role' => 'owner', 'user_id' => $other->id]
        );

        $response->assertOk();

        $this->assertDatabaseHas('household_members', [
            'id' => $membership->id,
            'user_id' => $member->id,
            'role' => 'owner',
        ]);
        $this->assertDatabaseMissing('household_members', [
            'household_id' => $household->id,
            'user_id' => $other->id,
        ]);
    });

    test('payload household_id cannot move membership target', function () {
        $owner = User::factory()->create();
        $household = makeHomfloHousehold($owner);
        $member = addHomfloMember($household);
        $membership = HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('user_id', $member->id)
            ->firstOrFail();
        $otherOwner = User::factory()->create();
        $otherHousehold = makeHomfloHousehold($otherOwner, 'Other Home');

        $response = $this->actingAs($owner)->patchJson(
            "/api/households/{$household->id}/members/{$membership->id}",
            ['role' => 'owner', 'household_id' => $otherHousehold->id]
        );

        $response->assertOk();

        $this->assertDatabaseHas('household_members', [
            'id' => $membership->id,
            'household_id' => $household->id,
        ]);
    });

    test('household cannot lose its last owner via role change', function () {
        $owner = User::factory()->create();
        $household = makeHomfloHousehold($owner);
        $membership = HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('user_id', $owner->id)
            ->firstOrFail();

        $response = $this->actingAs($owner)->patchJson(
            "/api/households/{$household->id}/members/{$membership->id}",
            ['role' => 'member']
        );

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'HOUSEHOLD_LAST_OWNER');

        $this->assertDatabaseHas('household_members', [
            'id' => $membership->id,
            'role' => 'owner',
        ]);
    });

    test('owner can remove a member', function () {
        $owner = User::factory()->create();
        $household = makeHomfloHousehold($owner);
        $member = addHomfloMember($household);
        $membership = HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('user_id', $member->id)
            ->firstOrFail();

        $response = $this->actingAs($owner)->deleteJson(
            "/api/households/{$household->id}/members/{$membership->id}"
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseMissing('household_members', ['id' => $membership->id]);
    });

    test('member cannot remove another member', function () {
        $owner = User::factory()->create();
        $household = makeHomfloHousehold($owner);
        $member = addHomfloMember($household);
        $victim = addHomfloMember($household);
        $victimMembership = HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('user_id', $victim->id)
            ->firstOrFail();

        $response = $this->actingAs($member)->deleteJson(
            "/api/households/{$household->id}/members/{$victimMembership->id}"
        );

        $response->assertForbidden();
        $response->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertDatabaseHas('household_members', ['id' => $victimMembership->id]);
    });

    test('member cannot remove the owner', function () {
        $owner = User::factory()->create();
        $household = makeHomfloHousehold($owner);
        $member = addHomfloMember($household);
        $ownerMembership = HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('user_id', $owner->id)
            ->firstOrFail();

        $response = $this->actingAs($member)->deleteJson(
            "/api/households/{$household->id}/members/{$ownerMembership->id}"
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('household_members', ['id' => $ownerMembership->id]);
    });

    test('cross-household user cannot remove a membership', function () {
        $owner = User::factory()->create();
        $household = makeHomfloHousehold($owner);
        $member = addHomfloMember($household);
        $membership = HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('user_id', $member->id)
            ->firstOrFail();
        $outsider = User::factory()->create();
        makeHomfloHousehold($outsider, 'Other Home');

        $response = $this->actingAs($outsider)->deleteJson(
            "/api/households/{$household->id}/members/{$membership->id}"
        );

        $response->assertNotFound();
        $response->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');

        $this->assertDatabaseHas('household_members', ['id' => $membership->id]);
    });

    test('household cannot lose its last owner via removal', function () {
        $owner = User::factory()->create();
        $household = makeHomfloHousehold($owner);
        $membership = HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('user_id', $owner->id)
            ->firstOrFail();

        $response = $this->actingAs($owner)->deleteJson(
            "/api/households/{$household->id}/members/{$membership->id}"
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'HOUSEHOLD_LAST_OWNER');

        $this->assertDatabaseHas('household_members', ['id' => $membership->id]);
    });

    test('guest cannot change or remove membership', function () {
        $owner = User::factory()->create();
        $household = makeHomfloHousehold($owner);
        $member = addHomfloMember($household);
        $membership = HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('user_id', $member->id)
            ->firstOrFail();

        $this->patchJson(
            "/api/households/{$household->id}/members/{$membership->id}",
            ['role' => 'owner']
        )->assertUnauthorized();

        $this->deleteJson(
            "/api/households/{$household->id}/members/{$membership->id}"
        )->assertUnauthorized();
    });
});
