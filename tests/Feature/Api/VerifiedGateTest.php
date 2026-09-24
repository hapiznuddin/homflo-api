<?php

use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Services\Household\HouseholdInvitationService;

function gateSetupHousehold(User $owner, string $name = 'Test Home'): Household
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

function gateInviteOwner(Household $household, User $owner, User $invited): array
{
    return app(HouseholdInvitationService::class)->invite(
        $household,
        $owner,
        $invited->email
    );
}

describe('Verified gate account access', function () {
    test('unverified user can login', function () {
        $user = User::factory()->unverified()->create();

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
    });

    test('unverified user can GET me', function () {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->getJson('/api/me');

        $response->assertOk();
        $response->assertJsonPath('data.user.email_verified_at', null);
    });

    test('unverified user can GET invitations', function () {
        $owner = User::factory()->create();
        $household = gateSetupHousehold($owner);
        $invited = User::factory()->unverified()->create();

        gateInviteOwner($household, $owner, $invited);

        $response = $this->actingAs($invited)->getJson('/api/invitations');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    });
});

describe('Verified gate household creation', function () {
    test('unverified user cannot create household', function () {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->postJson('/api/households', ['name' => 'Rumah Hapiz']);

        $response->assertForbidden();
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
    });

    test('no household is created for unverified user', function () {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->postJson('/api/households', ['name' => 'Rumah Hapiz']);

        $this->assertDatabaseMissing('households', ['name' => 'Rumah Hapiz']);
        $this->assertDatabaseMissing('household_members', ['user_id' => $user->id]);
    });

    test('verified user can create household', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/households', ['name' => 'Rumah Hapiz']);

        $response->assertStatus(201);
        $response->assertJsonPath('data.household.role', 'owner');
    });
});

describe('Verified gate invitation acceptance', function () {
    test('unverified user cannot accept invitation', function () {
        $owner = User::factory()->create();
        $household = gateSetupHousehold($owner);
        $invited = User::factory()->unverified()->create();

        $created = gateInviteOwner($household, $owner, $invited);

        $response = $this->actingAs($invited)->postJson(
            "/api/invitations/{$created['invitation']['id']}/accept",
            ['token' => $created['token']]
        );

        $response->assertForbidden();
        $response->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
    });

    test('rejected acceptance leaves invitation pending without membership', function () {
        $owner = User::factory()->create();
        $household = gateSetupHousehold($owner);
        $invited = User::factory()->unverified()->create();

        $created = gateInviteOwner($household, $owner, $invited);

        $this->actingAs($invited)->postJson(
            "/api/invitations/{$created['invitation']['id']}/accept",
            ['token' => $created['token']]
        );

        expect(HouseholdInvitation::find($created['invitation']['id'])->status)->toBe('pending');

        $this->assertDatabaseMissing('household_members', [
            'household_id' => $household->id,
            'user_id' => $invited->id,
        ]);
    });

    test('verified user can accept valid invitation', function () {
        $owner = User::factory()->create();
        $household = gateSetupHousehold($owner);
        $invited = User::factory()->create();

        $created = gateInviteOwner($household, $owner, $invited);

        $response = $this->actingAs($invited)->postJson(
            "/api/invitations/{$created['invitation']['id']}/accept",
            ['token' => $created['token']]
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.role', 'member');
    });
});

describe('Verified gate invitation creation', function () {
    test('unverified owner cannot invite', function () {
        $owner = User::factory()->unverified()->create();
        $household = gateSetupHousehold($owner);
        $invited = User::factory()->create();

        $response = $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations",
            ['email' => $invited->email]
        );

        $response->assertForbidden();
        $response->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
    });

    test('no invitation is created by unverified owner', function () {
        $owner = User::factory()->unverified()->create();
        $household = gateSetupHousehold($owner);
        $invited = User::factory()->create();

        $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations",
            ['email' => $invited->email]
        );

        $this->assertDatabaseMissing('household_invitations', [
            'household_id' => $household->id,
            'email' => strtolower($invited->email),
        ]);
    });

    test('verified owner can invite', function () {
        $owner = User::factory()->create();
        $household = gateSetupHousehold($owner);
        $invited = User::factory()->create();

        $response = $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations",
            ['email' => $invited->email]
        );

        $response->assertStatus(201);
    });

    test('verified member still receives forbidden when inviting', function () {
        $owner = User::factory()->create();
        $household = gateSetupHousehold($owner);
        $member = User::factory()->create();
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
        $invited = User::factory()->create();

        $response = $this->actingAs($member)->postJson(
            "/api/households/{$household->id}/invitations",
            ['email' => $invited->email]
        );

        $response->assertForbidden();
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    });
});

describe('Verified gate membership management', function () {
    test('unverified user cannot change role', function () {
        $owner = User::factory()->unverified()->create();
        $household = gateSetupHousehold($owner);
        $member = User::factory()->create();
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
        $membership = HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('user_id', $member->id)
            ->firstOrFail();

        $response = $this->actingAs($owner)->patchJson(
            "/api/households/{$household->id}/members/{$membership->id}",
            ['role' => 'owner']
        );

        $response->assertForbidden();
        $response->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
    });

    test('unverified user cannot remove member', function () {
        $owner = User::factory()->unverified()->create();
        $household = gateSetupHousehold($owner);
        $member = User::factory()->create();
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
        $membership = HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('user_id', $member->id)
            ->firstOrFail();

        $response = $this->actingAs($owner)->deleteJson(
            "/api/households/{$household->id}/members/{$membership->id}"
        );

        $response->assertForbidden();
        $response->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->assertDatabaseHas('household_members', ['id' => $membership->id]);
    });

    test('verified owner can change role', function () {
        $owner = User::factory()->create();
        $household = gateSetupHousehold($owner);
        $member = User::factory()->create();
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
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

    test('verified owner can remove member', function () {
        $owner = User::factory()->create();
        $household = gateSetupHousehold($owner);
        $member = User::factory()->create();
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
        $membership = HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('user_id', $member->id)
            ->firstOrFail();

        $response = $this->actingAs($owner)->deleteJson(
            "/api/households/{$household->id}/members/{$membership->id}"
        );

        $response->assertOk();

        $this->assertDatabaseMissing('household_members', ['id' => $membership->id]);
    });

    test('verified member still receives forbidden for management', function () {
        $owner = User::factory()->create();
        $household = gateSetupHousehold($owner);
        $member = User::factory()->create();
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
        $membership = HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('user_id', $member->id)
            ->firstOrFail();

        $this->actingAs($member)->patchJson(
            "/api/households/{$household->id}/members/{$membership->id}",
            ['role' => 'owner']
        )->assertForbidden();

        $this->actingAs($member)->deleteJson(
            "/api/households/{$household->id}/members/{$membership->id}"
        )->assertForbidden();
    });
});

describe('Verified gate member listing', function () {
    test('unverified member can still list members through policy', function () {
        $owner = User::factory()->create();
        $household = gateSetupHousehold($owner);
        $member = User::factory()->unverified()->create();
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);

        $response = $this->actingAs($member)->getJson("/api/households/{$household->id}/members");

        $response->assertOk();
        $response->assertJsonPath('success', true);
    });

    test('verified cross-household user cannot access another household', function () {
        $owner = User::factory()->create();
        $household = gateSetupHousehold($owner);
        $outsider = User::factory()->create();
        gateSetupHousehold($outsider, 'Other Home');

        $response = $this->actingAs($outsider)->getJson("/api/households/{$household->id}/members");

        $response->assertNotFound();
        $response->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');
    });
});

describe('Verified gate tampering', function () {
    test('client payload cannot bypass verification', function () {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->postJson('/api/households', [
            'name' => 'Rumah Hapiz',
            'user_id' => $user->id,
            'household_id' => '01K6W5Z5Z5Z5Z5Z5Z5Z5Z5Z5Z5',
            'role' => 'owner',
            'email' => $user->email,
        ]);

        $response->assertForbidden();
        $response->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->assertDatabaseMissing('households', ['name' => 'Rumah Hapiz']);
    });
});
