<?php

use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Repositories\Contracts\HouseholdInvitationRepositoryInterface;
use App\Services\Household\HouseholdInvitationService;
use Illuminate\Database\Eloquent\Collection;

function invSetupHousehold(User $owner, string $name = 'Test Home'): Household
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

function invInvite(object $testCase, User $owner, Household $household, string $email): array
{
    $response = $testCase->actingAs($owner)->postJson(
        "/api/households/{$household->id}/invitations",
        ['email' => $email]
    );

    $response->assertStatus(201);

    return [
        'id' => $response->json('data.invitation.id'),
        'token' => $response->json('data.token'),
    ];
}

describe('Household invitations', function () {
    test('owner can create invitation', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);
        $invited = User::factory()->create();

        $response = $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations",
            ['email' => $invited->email]
        );

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.invitation.email', strtolower($invited->email));
        $response->assertJsonPath('data.invitation.status', 'pending');
        expect($response->json('data.token'))->toBeString()->not->toBeEmpty();

        $this->assertDatabaseHas('household_invitations', [
            'household_id' => $household->id,
            'email' => strtolower($invited->email),
            'invited_by' => $owner->id,
            'status' => 'pending',
        ]);
    });

    test('only token hash is persisted, never the raw token', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);
        $invited = User::factory()->create();

        $created = invInvite($this, $owner, $household, $invited->email);

        $stored = HouseholdInvitation::find($created['id']);

        expect($stored->token_hash)->not->toBe($created['token'])
            ->and($stored->token_hash)->toBe(hash('sha256', $created['token']));
    });

    test('member cannot create invitation', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);
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

    test('non-member cannot create invitation', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);
        $outsider = User::factory()->create();
        invSetupHousehold($outsider, 'Other Home');
        $invited = User::factory()->create();

        $response = $this->actingAs($outsider)->postJson(
            "/api/households/{$household->id}/invitations",
            ['email' => $invited->email]
        );

        $response->assertNotFound();
        $response->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');
    });

    test('guest cannot create invitation', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);

        $this->postJson(
            "/api/households/{$household->id}/invitations",
            ['email' => 'someone@example.com']
        )->assertUnauthorized();
    });

    test('invalid email returns validation error', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);

        $response = $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations",
            ['email' => 'not-an-email']
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    });

    test('client household_id cannot override route household', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);
        $otherOwner = User::factory()->create();
        $otherHousehold = invSetupHousehold($otherOwner, 'Other Home');
        $invited = User::factory()->create();

        $response = $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations",
            ['email' => $invited->email, 'household_id' => $otherHousehold->id]
        );

        $response->assertStatus(201);

        $this->assertDatabaseHas('household_invitations', [
            'email' => strtolower($invited->email),
            'household_id' => $household->id,
        ]);
        $this->assertDatabaseMissing('household_invitations', [
            'email' => strtolower($invited->email),
            'household_id' => $otherHousehold->id,
        ]);
    });

    test('client user_id inviter and role cannot control invitation', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);
        $invited = User::factory()->create();
        $other = User::factory()->create();

        $response = $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations",
            [
                'email' => $invited->email,
                'user_id' => $other->id,
                'invited_by' => $other->id,
                'role' => 'owner',
            ]
        );

        $response->assertStatus(201);

        $this->assertDatabaseHas('household_invitations', [
            'email' => strtolower($invited->email),
            'household_id' => $household->id,
            'invited_by' => $owner->id,
        ]);
    });

    test('duplicate pending invitation is rejected', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);
        $invited = User::factory()->create();

        invInvite($this, $owner, $household, $invited->email);

        $response = $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations",
            ['email' => $invited->email]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INVITATION_ALREADY_PENDING');
    });

    test('inviting an existing member is rejected', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);
        $member = User::factory()->create();
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);

        $response = $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations",
            ['email' => $member->email]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'ALREADY_HOUSEHOLD_MEMBER');
    });

    test('owner inviting own email is rejected as already member', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);

        $response = $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations",
            ['email' => $owner->email]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'ALREADY_HOUSEHOLD_MEMBER');
    });

    test('inviting unknown email is rejected without creating users', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);

        $response = $this->actingAs($owner)->postJson(
            "/api/households/{$household->id}/invitations",
            ['email' => 'ghost@example.com']
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INVITED_USER_NOT_FOUND');
        expect(User::where('email', 'ghost@example.com')->exists())->toBeFalse();
    });

    test('invited user can see own invitation without secret token', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);
        $invited = User::factory()->create();

        $created = invInvite($this, $owner, $household, $invited->email);

        $response = $this->actingAs($invited)->getJson('/api/invitations');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonFragment(['id' => $created['id']]);
        expect($response->getContent())->not->toContain('token_hash');
    });

    test('user cannot see another user invitation', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);
        $invited = User::factory()->create();
        $other = User::factory()->create();

        $created = invInvite($this, $owner, $household, $invited->email);

        $response = $this->actingAs($other)->getJson('/api/invitations');

        $response->assertOk();
        expect($response->getContent())->not->toContain($created['id']);
    });

    test('guest cannot list invitations', function () {
        $this->getJson('/api/invitations')->assertUnauthorized();
    });

    test('invitation from another household is not exposed to the wrong user', function () {
        $ownerA = User::factory()->create();
        $householdA = invSetupHousehold($ownerA, 'Home A');
        $ownerB = User::factory()->create();
        $householdB = invSetupHousehold($ownerB, 'Home B');
        $invitedB = User::factory()->create();
        $invitedA = User::factory()->create();

        invInvite($this, $ownerB, $householdB, $invitedB->email);

        $response = $this->actingAs($invitedA)->getJson('/api/invitations');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    });

    test('invited user can accept invitation', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);
        $invited = User::factory()->create();

        $created = invInvite($this, $owner, $household, $invited->email);

        $response = $this->actingAs($invited)->postJson(
            "/api/invitations/{$created['id']}/accept",
            ['token' => $created['token']]
        );

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.user_id', $invited->id);
        $response->assertJsonPath('data.role', 'member');
    });

    test('accepting creates household_members row with member role', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);
        $invited = User::factory()->create();

        $created = invInvite($this, $owner, $household, $invited->email);

        $this->actingAs($invited)->postJson(
            "/api/invitations/{$created['id']}/accept",
            ['token' => $created['token']]
        )->assertStatus(201);

        $this->assertDatabaseHas('household_members', [
            'household_id' => $household->id,
            'user_id' => $invited->id,
            'role' => 'member',
        ]);
    });

    test('invitation becomes accepted with accepted_at populated', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);
        $invited = User::factory()->create();

        $created = invInvite($this, $owner, $household, $invited->email);

        $this->actingAs($invited)->postJson(
            "/api/invitations/{$created['id']}/accept",
            ['token' => $created['token']]
        )->assertStatus(201);

        $invitation = HouseholdInvitation::find($created['id']);

        expect($invitation->status)->toBe('accepted')
            ->and($invitation->accepted_at)->not->toBeNull();
    });

    test('invitation cannot be accepted twice', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);
        $invited = User::factory()->create();

        $created = invInvite($this, $owner, $household, $invited->email);

        $this->actingAs($invited)->postJson(
            "/api/invitations/{$created['id']}/accept",
            ['token' => $created['token']]
        )->assertStatus(201);

        $response = $this->actingAs($invited)->postJson(
            "/api/invitations/{$created['id']}/accept",
            ['token' => $created['token']]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INVITATION_ALREADY_ACCEPTED');

        expect(HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('user_id', $invited->id)
            ->count())->toBe(1);
    });

    test('expired invitation cannot be accepted', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);
        $invited = User::factory()->create();

        $created = invInvite($this, $owner, $household, $invited->email);

        HouseholdInvitation::find($created['id'])->update([
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($invited)->postJson(
            "/api/invitations/{$created['id']}/accept",
            ['token' => $created['token']]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INVITATION_EXPIRED');

        $this->assertDatabaseMissing('household_members', [
            'household_id' => $household->id,
            'user_id' => $invited->id,
        ]);
    });

    test('invitation for another user cannot be accepted', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);
        $invited = User::factory()->create();
        $impostor = User::factory()->create();
        invSetupHousehold($impostor, 'Other Home');

        $created = invInvite($this, $owner, $household, $invited->email);

        $response = $this->actingAs($impostor)->postJson(
            "/api/invitations/{$created['id']}/accept",
            ['token' => $created['token']]
        );

        $response->assertNotFound();
        $response->assertJsonPath('error.code', 'INVITATION_NOT_FOUND');
    });

    test('wrong token cannot accept invitation', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);
        $invited = User::factory()->create();

        $created = invInvite($this, $owner, $household, $invited->email);

        $response = $this->actingAs($invited)->postJson(
            "/api/invitations/{$created['id']}/accept",
            ['token' => 'wrong-token-value']
        );

        $response->assertNotFound();
        $response->assertJsonPath('error.code', 'INVITATION_NOT_FOUND');
    });

    test('already-member cannot create duplicate membership via accept', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);
        $invited = User::factory()->create();

        $created = invInvite($this, $owner, $household, $invited->email);

        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $invited->id,
            'role' => 'member',
        ]);

        $response = $this->actingAs($invited)->postJson(
            "/api/invitations/{$created['id']}/accept",
            ['token' => $created['token']]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'ALREADY_HOUSEHOLD_MEMBER');

        expect(HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('user_id', $invited->id)
            ->count())->toBe(1);
    });

    test('client user_id cannot change accept target user', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);
        $invited = User::factory()->create();
        $other = User::factory()->create();

        $created = invInvite($this, $owner, $household, $invited->email);

        $response = $this->actingAs($invited)->postJson(
            "/api/invitations/{$created['id']}/accept",
            ['token' => $created['token'], 'user_id' => $other->id]
        );

        $response->assertStatus(201);

        $this->assertDatabaseHas('household_members', [
            'household_id' => $household->id,
            'user_id' => $invited->id,
        ]);
        $this->assertDatabaseMissing('household_members', [
            'household_id' => $household->id,
            'user_id' => $other->id,
        ]);
    });

    test('failed acceptance leaves no partial membership or mutation', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);
        $invited = User::factory()->create();

        $created = invInvite($this, $owner, $household, $invited->email);

        $inner = app(HouseholdInvitationRepositoryInterface::class);

        $failingInvitations = new class($inner) implements HouseholdInvitationRepositoryInterface
        {
            public function __construct(private readonly HouseholdInvitationRepositoryInterface $inner) {}

            public function create(array $data): HouseholdInvitation
            {
                return $this->inner->create($data);
            }

            public function findById(string $id): ?HouseholdInvitation
            {
                return $this->inner->findById($id);
            }

            public function findPendingByHouseholdAndEmail(Household $household, string $email): ?HouseholdInvitation
            {
                return $this->inner->findPendingByHouseholdAndEmail($household, $email);
            }

            public function listPendingForEmail(string $email): Collection
            {
                return $this->inner->listPendingForEmail($email);
            }

            public function markAsAccepted(HouseholdInvitation $invitation): HouseholdInvitation
            {
                throw new RuntimeException('acceptance failed');
            }
        };

        app()->instance(HouseholdInvitationRepositoryInterface::class, $failingInvitations);

        $service = app(HouseholdInvitationService::class);

        expect(fn () => $service->accept($invited, $created['id'], $created['token']))
            ->toThrow(RuntimeException::class);

        $this->assertDatabaseMissing('household_members', [
            'household_id' => $household->id,
            'user_id' => $invited->id,
        ]);

        $invitation = HouseholdInvitation::find($created['id']);

        expect($invitation->status)->toBe('pending')
            ->and($invitation->accepted_at)->toBeNull();
    });

    test('household A owner cannot manipulate household B invitation', function () {
        $ownerA = User::factory()->create();
        invSetupHousehold($ownerA, 'Home A');
        $ownerB = User::factory()->create();
        $householdB = invSetupHousehold($ownerB, 'Home B');

        $invited = User::factory()->create();

        $response = $this->actingAs($ownerA)->postJson(
            "/api/households/{$householdB->id}/invitations",
            ['email' => $invited->email]
        );

        $response->assertNotFound();
        $response->assertJsonPath('error.code', 'HOUSEHOLD_NOT_FOUND');

        $this->assertDatabaseMissing('household_invitations', [
            'household_id' => $householdB->id,
            'email' => strtolower($invited->email),
        ]);
    });

    test('accepted member is manageable via existing member endpoints', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);
        $invited = User::factory()->create();

        $created = invInvite($this, $owner, $household, $invited->email);

        $this->actingAs($invited)->postJson(
            "/api/invitations/{$created['id']}/accept",
            ['token' => $created['token']]
        )->assertStatus(201);

        $list = $this->actingAs($owner)->getJson("/api/households/{$household->id}/members");

        $list->assertOk();
        $list->assertJsonFragment(['user_id' => $invited->id, 'role' => 'member']);

        $me = $this->actingAs($invited)->getJson('/api/me');

        $me->assertOk();
        $me->assertJsonPath('data.household.id', $household->id);
        $me->assertJsonPath('data.household.role', 'member');
        $me->assertJsonPath('data.onboarding.required', false);
    });

    test('guest cannot accept invitation', function () {
        $owner = User::factory()->create();
        $household = invSetupHousehold($owner);
        $invited = User::factory()->create();

        $created = invInvite($this, $owner, $household, $invited->email);

        app('auth')->forgetGuards();

        $this->postJson(
            "/api/invitations/{$created['id']}/accept",
            ['token' => $created['token']]
        )->assertUnauthorized();
    });
});
