<?php

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;

describe('GET /api/me', function () {
    test('returns 401 for unauthenticated request', function () {
        $response = $this->getJson('/api/me');

        $response->assertStatus(401);
    });

    test('returns user data when authenticated', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/me');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.user.id', $user->id);
        $response->assertJsonPath('data.user.email', $user->email);
    });

    test('does not expose password in response', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/me');

        $response->assertStatus(200);
        $response->assertJsonMissing(['password', 'two_factor_secret', 'two_factor_recovery_codes']);
    });

    test('returns onboarding required when user has no household membership', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/me');

        $response->assertStatus(200);
        $response->assertJsonPath('data.household', null);
        $response->assertJsonPath('data.onboarding.required', true);
    });

    test('returns household context when user has a membership', function () {
        $user = User::factory()->create();

        $household = Household::create([
            'name' => 'Smith Family',
            'created_by' => $user->id,
        ]);

        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $response = $this->actingAs($user)->getJson('/api/me');

        $response->assertStatus(200);
        $response->assertJsonPath('data.household.id', $household->id);
        $response->assertJsonPath('data.household.name', 'Smith Family');
        $response->assertJsonPath('data.household.role', 'owner');
        $response->assertJsonPath('data.onboarding.required', false);
    });

    test('household resolved from server — not from request input', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $household = Household::create([
            'name' => "Attacker's Household",
            'created_by' => $otherUser->id,
        ]);

        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $otherUser->id,
            'role' => 'owner',
        ]);

        // User has no membership — should not inherit other household even if ID were sent
        $response = $this->actingAs($user)->getJson('/api/me');

        $response->assertJsonPath('data.household', null);
        $response->assertJsonPath('data.onboarding.required', true);
    });

    test('response follows success contract structure', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/me');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'user',
                'household',
                'onboarding' => ['required'],
            ],
        ]);
    });
});
