<?php

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;

describe('Mass assignment tampering', function () {
    test('household creation ignores security fields', function () {
        $user = User::factory()->unverified()->create();

        // Unverified users cannot create households; verify first without
        // touching the payload path under test.
        $user->markEmailAsVerified();

        $response = $this->actingAs($user)->postJson('/api/households', [
            'name' => 'Rumah Hapiz',
            'user_id' => 'someone-else',
            'household_id' => 'another-household',
            'role' => 'member',
            'owner' => true,
            'is_owner' => true,
            'permissions' => ['admin'],
            'email_verified_at' => now()->toDateTimeString(),
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.household.role', 'owner');

        $this->assertDatabaseMissing('household_members', [
            'user_id' => $user->id,
            'role' => 'member',
        ]);
    });

    test('member update ignores security fields', function () {
        $owner = User::factory()->create();
        $household = Household::create(['name' => 'Test Home', 'created_by' => $owner->id]);
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
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
            [
                'role' => 'owner',
                'email_verified_at' => now()->toDateTimeString(),
                'is_owner' => true,
                'permissions' => ['admin'],
            ]
        );

        $response->assertOk();
        $response->assertJsonPath('data.role', 'owner');
        $response->assertJsonMissing(['email_verified_at', 'is_owner', 'permissions']);
    });

    test('password change ignores verification and identity fields', function () {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->postJson('/api/password/change', [
            'current_password' => 'password',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
            'email_verified_at' => now()->toDateTimeString(),
            'user_id' => User::factory()->create()->id,
            'role' => 'owner',
        ]);

        $response->assertOk();

        expect($user->fresh()->email_verified_at)->toBeNull();
    });

    test('invitation creation ignores status and token fields', function () {
        $owner = User::factory()->create();
        $household = Household::create(['name' => 'Test Home', 'created_by' => $owner->id]);
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
        $invited = User::factory()->create();

        $response = $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations",
            [
                'email' => $invited->email,
                'status' => 'accepted',
                'token_hash' => 'forged',
                'role' => 'owner',
            ]
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.invitation.status', 'pending');

        $this->assertDatabaseMissing('household_invitations', [
            'email' => strtolower($invited->email),
            'token_hash' => 'forged',
        ]);
    });

    test('invitation acceptance ignores role field', function () {
        $owner = User::factory()->create();
        $household = Household::create(['name' => 'Test Home', 'created_by' => $owner->id]);
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
        $invited = User::factory()->create();

        $created = $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations",
            ['email' => $invited->email]
        )->json();

        $response = $this->actingAs($invited)->postJson(
            "/api/invitations/{$created['data']['invitation']['id']}/accept",
            ['token' => $created['data']['token'], 'role' => 'owner', 'is_owner' => true]
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.role', 'member');
    });
});
