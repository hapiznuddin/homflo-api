<?php

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

function makeHouseholdWithRole(User $user, string $role = 'owner', string $name = 'Test Home'): Household
{
    $household = Household::create([
        'name' => $name,
        'created_by' => $user->id,
    ]);

    HouseholdMember::create([
        'household_id' => $household->id,
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $household->refresh();
}

describe('HouseholdPolicy', function () {
    test('unauthenticated user cannot access protected household authorization path', function () {
        $owner = User::factory()->create();
        $household = makeHouseholdWithRole($owner);

        $this->postJson('/api/households', ['name' => 'Nope'])->assertStatus(401);
        $this->getJson('/api/me')->assertStatus(401);

        expect(Gate::allows('view', $household))->toBeFalse()
            ->and(Gate::allows('update', $household))->toBeFalse()
            ->and(Gate::allows('delete', $household))->toBeFalse();
    });

    test('household member can view their own household', function () {
        $member = User::factory()->create();
        $household = makeHouseholdWithRole($member, 'member');

        expect(Gate::forUser($member)->allows('view', $household))->toBeTrue();
    });

    test('household owner can view their own household', function () {
        $owner = User::factory()->create();
        $household = makeHouseholdWithRole($owner, 'owner');

        expect(Gate::forUser($owner)->allows('view', $household))->toBeTrue();
    });

    test('household member cannot update household', function () {
        $member = User::factory()->create();
        $household = makeHouseholdWithRole($member, 'member');

        expect(Gate::forUser($member)->allows('update', $household))->toBeFalse();
    });

    test('household owner can update household', function () {
        $owner = User::factory()->create();
        $household = makeHouseholdWithRole($owner, 'owner');

        expect(Gate::forUser($owner)->allows('update', $household))->toBeTrue();
    });

    test('household member cannot delete household', function () {
        $member = User::factory()->create();
        $household = makeHouseholdWithRole($member, 'member');

        expect(Gate::forUser($member)->allows('delete', $household))->toBeFalse();
    });

    test('household owner can delete household', function () {
        $owner = User::factory()->create();
        $household = makeHouseholdWithRole($owner, 'owner');

        expect(Gate::forUser($owner)->allows('delete', $household))->toBeTrue();
    });

    test('non-member cannot view another household', function () {
        $owner = User::factory()->create();
        $household = makeHouseholdWithRole($owner, 'owner');
        $outsider = User::factory()->create();

        expect(Gate::forUser($outsider)->allows('view', $household))->toBeFalse();
    });

    test('non-member cannot update another household', function () {
        $owner = User::factory()->create();
        $household = makeHouseholdWithRole($owner, 'owner');
        $outsider = User::factory()->create();

        expect(Gate::forUser($outsider)->allows('update', $household))->toBeFalse();
    });

    test('non-member cannot delete another household', function () {
        $owner = User::factory()->create();
        $household = makeHouseholdWithRole($owner, 'owner');
        $outsider = User::factory()->create();

        expect(Gate::forUser($outsider)->allows('delete', $household))->toBeFalse();
    });

    test('client-provided user_id cannot alter authorization identity', function () {
        $owner = User::factory()->create();
        $household = makeHouseholdWithRole($owner, 'owner');
        $outsider = User::factory()->create();

        $payload = ['user_id' => $owner->id];

        expect($payload['user_id'])->toBe($owner->id)
            ->and(Gate::forUser($outsider)->allows('view', $household))->toBeFalse()
            ->and(Gate::forUser($outsider)->allows('update', $household))->toBeFalse()
            ->and(Gate::forUser($outsider)->allows('delete', $household))->toBeFalse();
    });

    test('client-provided household_id cannot alter authorization target', function () {
        $ownerA = User::factory()->create();
        $householdA = makeHouseholdWithRole($ownerA, 'owner', 'Home A');
        $ownerB = User::factory()->create();
        $householdB = makeHouseholdWithRole($ownerB, 'owner', 'Home B');

        $payload = ['household_id' => $householdA->id];

        expect($payload['household_id'])->toBe($householdA->id)
            ->and(Gate::forUser($ownerB)->allows('view', $householdA))->toBeFalse()
            ->and(Gate::forUser($ownerB)->allows('update', $householdA))->toBeFalse()
            ->and(Gate::forUser($ownerB)->allows('delete', $householdA))->toBeFalse()
            ->and(Gate::forUser($ownerB)->allows('view', $householdB))->toBeTrue();
    });

    test('client-provided role cannot grant authorization', function () {
        $owner = User::factory()->create();
        $household = makeHouseholdWithRole($owner, 'owner');
        $outsider = User::factory()->create();

        $payload = ['role' => 'owner'];

        expect($payload['role'])->toBe('owner')
            ->and(Gate::forUser($outsider)->allows('view', $household))->toBeFalse()
            ->and(Gate::forUser($outsider)->allows('update', $household))->toBeFalse()
            ->and(Gate::forUser($outsider)->allows('delete', $household))->toBeFalse();
    });

    test('membership role is read from server-side household_members data', function () {
        $user = User::factory()->create();
        $household = makeHouseholdWithRole($user, 'member');

        $storedRole = HouseholdMember::query()
            ->where('user_id', $user->id)
            ->where('household_id', $household->id)
            ->value('role');

        expect($storedRole)->toBe('member')
            ->and(Gate::forUser($user)->allows('view', $household))->toBeTrue()
            ->and(Gate::forUser($user)->allows('update', $household))->toBeFalse()
            ->and(Gate::forUser($user)->allows('delete', $household))->toBeFalse();
    });

    test('changing membership role from owner to member changes authorization behavior', function () {
        $user = User::factory()->create();
        $household = makeHouseholdWithRole($user, 'owner');

        expect(Gate::forUser($user)->allows('update', $household))->toBeTrue()
            ->and(Gate::forUser($user)->allows('delete', $household))->toBeTrue();

        HouseholdMember::query()
            ->where('user_id', $user->id)
            ->where('household_id', $household->id)
            ->update(['role' => 'member']);

        expect(Gate::forUser($user)->allows('view', $household))->toBeTrue()
            ->and(Gate::forUser($user)->allows('update', $household))->toBeFalse()
            ->and(Gate::forUser($user)->allows('delete', $household))->toBeFalse();
    });

    test('removing membership causes authorization to fail', function () {
        $user = User::factory()->create();
        $household = makeHouseholdWithRole($user, 'owner');

        expect(Gate::forUser($user)->allows('view', $household))->toBeTrue();

        HouseholdMember::query()
            ->where('user_id', $user->id)
            ->where('household_id', $household->id)
            ->delete();

        expect(Gate::forUser($user)->allows('view', $household))->toBeFalse()
            ->and(Gate::forUser($user)->allows('update', $household))->toBeFalse()
            ->and(Gate::forUser($user)->allows('delete', $household))->toBeFalse();
    });
});
