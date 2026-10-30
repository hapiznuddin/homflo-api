<?php

use App\Models\User;
use App\Models\UserPreference;

describe('GET /api/preferences', function () {
    test('guest cannot read preferences', function () {
        $this->getJson('/api/preferences')->assertUnauthorized();
    });

    test('user without a stored row receives the system default without creating a row', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/preferences');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.theme', 'system');

        expect(UserPreference::query()->where('user_id', $user->id)->exists())->toBeFalse();
    });

    test('stored preference is returned', function () {
        $user = User::factory()->create();

        UserPreference::create(['user_id' => $user->id, 'theme' => 'dark']);

        $this->actingAs($user)->getJson('/api/preferences')
            ->assertOk()
            ->assertJsonPath('data.theme', 'dark');
    });

    test('preferences are per user, not per household or device', function () {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($first)->patchJson('/api/preferences', ['theme' => 'dark'])->assertOk();

        $this->actingAs($first)->getJson('/api/preferences')->assertJsonPath('data.theme', 'dark');
        $this->actingAs($second)->getJson('/api/preferences')->assertJsonPath('data.theme', 'system');
    });
});

describe('PATCH /api/preferences', function () {
    test('guest cannot update preferences', function () {
        $this->patchJson('/api/preferences', ['theme' => 'dark'])->assertUnauthorized();
    });

    test('user can save a supported theme', function (string $theme) {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patchJson('/api/preferences', ['theme' => $theme]);

        $response->assertOk();
        $response->assertJsonPath('data.theme', $theme);

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $user->id,
            'theme' => $theme,
        ]);
    })->with(['system', 'light', 'dark']);

    test('saving twice updates the same row', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/preferences', ['theme' => 'dark'])->assertOk();
        $this->actingAs($user)->patchJson('/api/preferences', ['theme' => 'light'])->assertOk();

        expect(UserPreference::query()->where('user_id', $user->id)->count())->toBe(1);

        $this->actingAs($user)->getJson('/api/preferences')->assertJsonPath('data.theme', 'light');
    });

    test('unsupported theme is rejected', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patchJson('/api/preferences', ['theme' => 'sepia']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['theme']);

        expect(UserPreference::query()->where('user_id', $user->id)->exists())->toBeFalse();
    });

    test('empty body is rejected', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patchJson('/api/preferences', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['theme']);
    });

    test('client user_id cannot write another user preference', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $response = $this->actingAs($user)->patchJson('/api/preferences', [
            'theme' => 'dark',
            'user_id' => $other->id,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('user_preferences', ['user_id' => $user->id, 'theme' => 'dark']);
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $other->id]);
    });

    test('preferences do not require a verified email', function () {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->patchJson('/api/preferences', ['theme' => 'light'])
            ->assertOk();
    });
});
