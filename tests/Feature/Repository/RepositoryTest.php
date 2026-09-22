<?php

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Repositories\Contracts\HouseholdMemberRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\HouseholdMemberRepository;
use App\Repositories\UserRepository;

describe('Repository bindings', function () {
    test('UserRepositoryInterface resolves to UserRepository', function () {
        expect(app(UserRepositoryInterface::class))
            ->toBeInstanceOf(UserRepository::class);
    });

    test('HouseholdMemberRepositoryInterface resolves to HouseholdMemberRepository', function () {
        expect(app(HouseholdMemberRepositoryInterface::class))
            ->toBeInstanceOf(HouseholdMemberRepository::class);
    });
});

describe('UserRepository', function () {
    test('creates a user and returns the model', function () {
        $repo = app(UserRepositoryInterface::class);

        $user = $repo->create([
            'username' => 'repouser',
            'name' => 'Repo User',
            'email' => 'repo@example.com',
            'password' => 'password123',
        ]);

        expect($user)->toBeInstanceOf(User::class)
            ->and($user->email)->toBe('repo@example.com')
            ->and($user->username)->toBe('repouser');
    });

    test('findByEmail returns correct user', function () {
        $created = User::factory()->create(['email' => 'findme@example.com']);

        $repo = app(UserRepositoryInterface::class);
        $found = $repo->findByEmail('findme@example.com');

        expect($found?->id)->toBe($created->id);
    });

    test('findByUsername returns correct user', function () {
        $created = User::factory()->create(['username' => 'findmyname']);

        $repo = app(UserRepositoryInterface::class);
        $found = $repo->findByUsername('findmyname');

        expect($found?->id)->toBe($created->id);
    });

    test('findByEmail returns null for unknown email', function () {
        $repo = app(UserRepositoryInterface::class);

        expect($repo->findByEmail('nobody@example.com'))->toBeNull();
    });
});

describe('HouseholdMemberRepository', function () {
    test('findPrimaryMembershipForUser returns null when user has no membership', function () {
        $user = User::factory()->create();
        $repo = app(HouseholdMemberRepositoryInterface::class);

        expect($repo->findPrimaryMembershipForUser($user))->toBeNull();
    });

    test('findPrimaryMembershipForUser returns membership with household loaded', function () {
        $owner = User::factory()->create();

        $household = Household::create([
            'name' => 'Test Family',
            'created_by' => $owner->id,
        ]);

        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $repo = app(HouseholdMemberRepositoryInterface::class);
        $membership = $repo->findPrimaryMembershipForUser($owner);

        expect($membership)->not->toBeNull()
            ->and($membership->household)->not->toBeNull()
            ->and($membership->household->id)->toBe($household->id);
    });
});
