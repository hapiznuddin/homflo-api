<?php

use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\HouseholdMember;
use App\Models\User;

function managedHousehold(User $owner, string $name = 'Rumah A'): Household
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

function managedInvitation(
    Household $household,
    User $inviter,
    string $email,
    string $status = HouseholdInvitation::STATUS_PENDING,
    ?string $expiresAt = null,
): HouseholdInvitation {
    return HouseholdInvitation::create([
        'household_id' => $household->id,
        'invited_by' => $inviter->id,
        'email' => $email,
        'token_hash' => hash('sha256', 'token-'.$email.'-'.$status),
        'status' => $status,
        'expires_at' => $expiresAt ?? now()->addDays(7),
        'accepted_at' => $status === HouseholdInvitation::STATUS_ACCEPTED ? now() : null,
    ]);
}

describe('GET /api/households/{household}/invitations', function () {
    test('guest cannot list household invitations', function () {
        $owner = User::factory()->create();
        $household = managedHousehold($owner);

        $this->getJson("/api/households/{$household->id}/invitations")->assertUnauthorized();
    });

    test('owner lists pending invitations by default without exposing tokens', function () {
        $owner = User::factory()->create();
        $household = managedHousehold($owner);

        $pending = managedInvitation($household, $owner, 'pending@example.com');
        managedInvitation($household, $owner, 'accepted@example.com', HouseholdInvitation::STATUS_ACCEPTED);
        managedInvitation($household, $owner, 'old@example.com', HouseholdInvitation::STATUS_PENDING, now()->subDay()->toDateTimeString());

        $response = $this->actingAs($owner)->getJson("/api/households/{$household->id}/invitations");

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $pending->id);
        $response->assertJsonPath('data.0.status', 'pending');
        $response->assertJsonPath('data.0.invited_by.id', $owner->id);

        expect($response->getContent())->not->toContain('token_hash')
            ->and($response->getContent())->not->toContain('"token"');
    });

    test('expired pending invitations are reported as expired', function () {
        $owner = User::factory()->create();
        $household = managedHousehold($owner);
        managedInvitation($household, $owner, 'old@example.com', HouseholdInvitation::STATUS_PENDING, now()->subDay()->toDateTimeString());

        $response = $this->actingAs($owner)
            ->getJson("/api/households/{$household->id}/invitations?status=expired");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.status', 'expired');
    });

    test('status filters select accepted, revoked, and all rows', function () {
        $owner = User::factory()->create();
        $household = managedHousehold($owner);

        managedInvitation($household, $owner, 'pending@example.com');
        managedInvitation($household, $owner, 'accepted@example.com', HouseholdInvitation::STATUS_ACCEPTED);
        $revoked = managedInvitation($household, $owner, 'revoked@example.com', HouseholdInvitation::STATUS_REVOKED);
        $revoked->forceFill(['revoked_at' => now()])->save();

        $accepted = $this->actingAs($owner)
            ->getJson("/api/households/{$household->id}/invitations?status=accepted");
        $accepted->assertOk();
        $accepted->assertJsonCount(1, 'data');
        $accepted->assertJsonPath('data.0.email', 'accepted@example.com');
        $accepted->assertJsonPath('data.0.status', 'accepted');

        $revokedResponse = $this->actingAs($owner)
            ->getJson("/api/households/{$household->id}/invitations?status=revoked");
        $revokedResponse->assertOk();
        $revokedResponse->assertJsonCount(1, 'data');
        $revokedResponse->assertJsonPath('data.0.email', 'revoked@example.com');
        $revokedResponse->assertJsonPath('data.0.status', 'revoked');

        $this->actingAs($owner)
            ->getJson("/api/households/{$household->id}/invitations?status=all")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    test('invalid status filter returns a validation error for status', function () {
        $owner = User::factory()->create();
        $household = managedHousehold($owner);

        $response = $this->actingAs($owner)
            ->getJson("/api/households/{$household->id}/invitations?status=unknown");

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    });

    test('member cannot list household invitations', function () {
        $owner = User::factory()->create();
        $household = managedHousehold($owner);
        $member = User::factory()->create();
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);

        $response = $this->actingAs($member)->getJson("/api/households/{$household->id}/invitations");

        $response->assertForbidden();
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    });

    test('non-member is masked as not found', function () {
        $owner = User::factory()->create();
        $household = managedHousehold($owner);
        $outsider = User::factory()->create();
        managedHousehold($outsider, 'Rumah Lain');

        $response = $this->actingAs($outsider)->getJson("/api/households/{$household->id}/invitations");

        $response->assertNotFound();
        $response->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');
    });

    test('owner only sees invitations of the addressed household', function () {
        $ownerA = User::factory()->create();
        $householdA = managedHousehold($ownerA, 'Rumah A');
        $ownerB = User::factory()->create();
        $householdB = managedHousehold($ownerB, 'Rumah B');

        managedInvitation($householdA, $ownerA, 'a@example.com');
        managedInvitation($householdB, $ownerB, 'b@example.com');

        $response = $this->actingAs($ownerA)->getJson("/api/households/{$householdA->id}/invitations");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        expect($response->getContent())->not->toContain('b@example.com');
    });
});

describe('POST /api/households/{household}/invitations/{invitation}/revoke', function () {
    test('guest cannot revoke an invitation', function () {
        $owner = User::factory()->create();
        $household = managedHousehold($owner);
        $invitation = managedInvitation($household, $owner, 'guest@example.com');

        $this->postJson("/api/households/{$household->id}/invitations/{$invitation->id}/revoke")
            ->assertUnauthorized();
    });

    test('owner revokes a pending invitation and its token can no longer be accepted', function () {
        $owner = User::factory()->create();
        $household = managedHousehold($owner);
        $invited = User::factory()->create();

        $invitation = HouseholdInvitation::create([
            'household_id' => $household->id,
            'invited_by' => $owner->id,
            'email' => $invited->email,
            'token_hash' => hash('sha256', 'revoke-token'),
            'status' => HouseholdInvitation::STATUS_PENDING,
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations/{$invitation->id}/revoke"
        );

        $response->assertOk();
        $response->assertJsonPath('data.id', $invitation->id);
        $response->assertJsonPath('data.status', 'revoked');

        $stored = $invitation->refresh();
        expect($stored->status)->toBe('revoked')
            ->and($stored->revoked_at)->not->toBeNull()
            ->and($stored->revoked_by)->toBe($owner->id);

        $accept = $this->actingAs($invited)->postJson(
            "/api/invitations/{$invitation->id}/accept",
            ['token' => 'revoke-token']
        );

        $accept->assertStatus(422);
        $accept->assertJsonPath('error.code', 'INVITATION_REVOKED');

        $this->assertDatabaseMissing('household_members', [
            'household_id' => $household->id,
            'user_id' => $invited->id,
        ]);
    });

    test('revoking twice is idempotent and keeps the original revoked_at', function () {
        $owner = User::factory()->create();
        $household = managedHousehold($owner);
        $invitation = managedInvitation($household, $owner, 'twice@example.com');

        $first = $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations/{$invitation->id}/revoke"
        );
        $first->assertOk();
        $revokedAt = $first->json('data.revoked_at');

        $second = $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations/{$invitation->id}/revoke"
        );
        $second->assertOk();
        expect($second->json('data.revoked_at'))->toBe($revokedAt);
    });

    test('an accepted invitation cannot be revoked', function () {
        $owner = User::factory()->create();
        $household = managedHousehold($owner);
        $invitation = managedInvitation($household, $owner, 'accepted@example.com', HouseholdInvitation::STATUS_ACCEPTED);

        $response = $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations/{$invitation->id}/revoke"
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INVITATION_ALREADY_ACCEPTED');

        expect($invitation->refresh()->status)->toBe('accepted');
    });

    test('an expired invitation can be revoked', function () {
        $owner = User::factory()->create();
        $household = managedHousehold($owner);
        $invitation = managedInvitation(
            $household,
            $owner,
            'expired@example.com',
            HouseholdInvitation::STATUS_PENDING,
            now()->subDay()->toDateTimeString()
        );

        $response = $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations/{$invitation->id}/revoke"
        );

        $response->assertOk();
        $response->assertJsonPath('data.status', 'revoked');
    });

    test('another household invitation is masked and stays pending', function () {
        $ownerA = User::factory()->create();
        $householdA = managedHousehold($ownerA, 'Rumah A');
        $ownerB = User::factory()->create();
        $householdB = managedHousehold($ownerB, 'Rumah B');
        $invitationB = managedInvitation($householdB, $ownerB, 'b@example.com');

        $response = $this->actingAs($ownerA)->postJson(
            "/api/households/{$householdA->id}/invitations/{$invitationB->id}/revoke"
        );

        $response->assertNotFound();
        $response->assertJsonPath('error.code', 'INVITATION_NOT_FOUND');
        expect($invitationB->refresh()->status)->toBe('pending');
    });

    test('member cannot revoke', function () {
        $owner = User::factory()->create();
        $household = managedHousehold($owner);
        $member = User::factory()->create();
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
        $invitation = managedInvitation($household, $owner, 'member@example.com');

        $response = $this->actingAs($member)->postJson(
            "/api/households/{$household->id}/invitations/{$invitation->id}/revoke"
        );

        $response->assertForbidden();
        $response->assertJsonPath('error.code', 'FORBIDDEN');
        expect($invitation->refresh()->status)->toBe('pending');
    });

    test('unverified owner cannot revoke', function () {
        $owner = User::factory()->unverified()->create();
        $household = managedHousehold($owner);
        $invitation = managedInvitation($household, $owner, 'unverified@example.com');

        $response = $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations/{$invitation->id}/revoke"
        );

        $response->assertForbidden();
        $response->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
        expect($invitation->refresh()->status)->toBe('pending');
    });

    test('a revoked email can be invited again', function () {
        $owner = User::factory()->create();
        $household = managedHousehold($owner);
        $invitation = managedInvitation($household, $owner, 'again@example.com');

        $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations/{$invitation->id}/revoke"
        )->assertOk();

        $response = $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations",
            ['email' => 'again@example.com']
        );

        $response->assertStatus(201);
        expect(HouseholdInvitation::query()
            ->where('household_id', $household->id)
            ->where('email', 'again@example.com')
            ->count())->toBe(2);
    });
});
