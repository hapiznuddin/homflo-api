<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

function cpChangePayload(array $overrides = []): array
{
    return array_merge([
        'current_password' => 'password',
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ], $overrides);
}

describe('Change password', function () {
    test('guest cannot change password', function () {
        $this->postJson('/api/password/change', cpChangePayload())->assertUnauthorized();
    });

    test('authenticated user can change password', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/password/change', cpChangePayload());

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.message', 'Password changed successfully.');
    });

    test('current password is required', function () {
        $user = User::factory()->create();
        $payload = cpChangePayload();
        unset($payload['current_password']);

        $response = $this->actingAs($user)->postJson('/api/password/change', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['current_password']);
    });

    test('wrong current password is rejected with envelope', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(
            '/api/password/change',
            cpChangePayload(['current_password' => 'wrong-password'])
        );

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'INVALID_CURRENT_PASSWORD');
    });

    test('new password is required', function () {
        $user = User::factory()->create();
        $payload = cpChangePayload();
        unset($payload['password']);

        $response = $this->actingAs($user)->postJson('/api/password/change', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    });

    test('password confirmation is required to match', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(
            '/api/password/change',
            cpChangePayload(['password_confirmation' => 'differentpassword'])
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    });

    test('weak new password is rejected by existing policy', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(
            '/api/password/change',
            cpChangePayload(['password' => 'short', 'password_confirmation' => 'short'])
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    });

    test('password is changed and stored hashed', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/password/change', cpChangePayload())->assertOk();

        $user->refresh();

        expect($user->password)->not->toBe('newpassword123');
        expect(Hash::check('newpassword123', $user->password))->toBeTrue();
    });

    test('old password no longer works and new password works', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/password/change', cpChangePayload())->assertOk();

        app('auth')->forgetGuards();

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(422);

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'newpassword123',
        ])->assertOk();
    });

    test('session remains valid after password change', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/password/change', cpChangePayload())->assertOk();

        $response = $this->actingAs($user)->getJson('/api/me');

        $response->assertOk();
        $response->assertJsonPath('data.user.id', $user->id);
    });

    test('response exposes no password hash or token', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/password/change', cpChangePayload());

        $response->assertOk();
        $response->assertJsonMissing(['password', 'token', 'plainTextToken']);
        expect($response->getContent())->not->toContain($user->fresh()->password);
    });

    test('client user_id cannot redirect password change to another user', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/password/change', array_merge(
            cpChangePayload(),
            ['user_id' => $other->id]
        ));

        $response->assertOk();

        expect(Hash::check('newpassword123', $user->fresh()->password))->toBeTrue();
        expect(Hash::check('newpassword123', $other->fresh()->password))->toBeFalse();
        expect(Hash::check('password', $other->fresh()->password))->toBeTrue();
    });

    test('client household_id and role cannot influence the operation', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/password/change', array_merge(
            cpChangePayload(),
            ['household_id' => '01K6W5Z5Z5Z5Z5Z5Z5Z5Z5Z5Z5', 'role' => 'owner']
        ));

        $response->assertOk();
        expect(Hash::check('newpassword123', $user->fresh()->password))->toBeTrue();
    });

    test('changing to the same password follows existing policy', function () {
        $user = User::factory()->create();

        // No password-history policy exists, so reusing the current
        // password is allowed by the existing password rules.
        $response = $this->actingAs($user)->postJson('/api/password/change', [
            'current_password' => 'password',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertOk();

        app('auth')->forgetGuards();

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
    });
});
