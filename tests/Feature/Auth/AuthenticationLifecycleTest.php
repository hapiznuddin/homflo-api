<?php

use App\Models\User;
use Illuminate\Testing\TestResponse;

function syncHomfloSessionCookie(object $testCase, TestResponse $response): void
{
    $name = config('session.cookie');

    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === $name && $cookie->getValue() !== null) {
            $testCase->withUnencryptedCookie($name, $cookie->getValue());
        }
    }
}

function freshHomfloRequest(): void
{
    app('auth')->forgetGuards();
}

beforeEach(function () {
    $this->withCredentials();
});

describe('Authentication lifecycle', function () {
    test('valid credentials authenticate successfully', function () {
        $user = User::factory()->create();

        $response = $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('two_factor', false);
        $this->assertAuthenticatedAs($user);
    });

    test('invalid password is rejected', function () {
        $user = User::factory()->create();

        $response = $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $this->assertGuest();
    });

    test('unknown email is rejected', function () {
        $response = $this->postJson('/login', [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
        $this->assertGuest();
    });

    test('missing login credentials are rejected', function () {
        $response = $this->postJson('/login', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email', 'password']);
        $this->assertGuest();
    });

    test('successful login authenticates the correct user', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertAuthenticatedAs($user);
        expect(auth()->id())->toBe($user->id)
            ->and(auth()->id())->not->toBe($otherUser->id);
    });

    test('GET me works after successful login and returns the same user', function () {
        $user = User::factory()->create();

        $login = $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
        syncHomfloSessionCookie($this, $login);
        freshHomfloRequest();

        $response = $this->getJson('/api/me');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.user.id', $user->id);
        $response->assertJsonPath('data.user.email', $user->email);
        $response->assertJsonPath('data.household', null);
        $response->assertJsonPath('data.onboarding.required', true);
    });

    test('GET me does not expose password or hash', function () {
        $user = User::factory()->create();

        $login = $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
        syncHomfloSessionCookie($this, $login);
        freshHomfloRequest();

        $response = $this->getJson('/api/me');

        $response->assertOk();
        $response->assertJsonMissing(['password']);
        expect($response->getContent())->not->toContain($user->getAuthPassword());
    });

    test('GET me does not expose authentication tokens', function () {
        $user = User::factory()->create();

        $loginResponse = $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        expect($loginResponse->getContent())->not->toContain('personal_access_token')
            ->and($loginResponse->getContent())->not->toContain('plainTextToken');

        syncHomfloSessionCookie($this, $loginResponse);
        freshHomfloRequest();

        $meResponse = $this->getJson('/api/me');

        $meResponse->assertOk();
        $meResponse->assertJsonMissing(['token', 'plainTextToken', 'personal_access_token']);
    });

    test('logout succeeds for an authenticated user', function () {
        $user = User::factory()->create();

        $login = $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
        syncHomfloSessionCookie($this, $login);

        $this->postJson('/logout')->assertNoContent();
        $this->assertGuest();
    });

    test('GET me fails after logout', function () {
        $user = User::factory()->create();

        $login = $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
        syncHomfloSessionCookie($this, $login);
        freshHomfloRequest();

        // Control: the session cookie genuinely authenticates a fresh request.
        $this->getJson('/api/me')->assertOk();
        freshHomfloRequest();

        $logout = $this->postJson('/logout');
        $logout->assertNoContent();
        syncHomfloSessionCookie($this, $logout);
        freshHomfloRequest();

        // The pre-logout session can no longer authenticate.
        $this->getJson('/api/me')->assertUnauthorized();
    });

    test('protected endpoint access fails after logout', function () {
        $user = User::factory()->create();

        $login = $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
        syncHomfloSessionCookie($this, $login);
        freshHomfloRequest();

        $logout = $this->postJson('/logout');
        $logout->assertNoContent();
        syncHomfloSessionCookie($this, $logout);
        freshHomfloRequest();

        $this->postJson('/api/households', ['name' => 'Rumah Hapiz'])
            ->assertUnauthorized();
    });

    test('logout does not accidentally authenticate another user', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $login = $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
        syncHomfloSessionCookie($this, $login);

        $logout = $this->postJson('/logout');
        $logout->assertNoContent();
        $this->assertGuest();
        freshHomfloRequest();

        $secondLogin = $this->postJson('/login', [
            'email' => $otherUser->email,
            'password' => 'password',
        ])->assertOk();
        syncHomfloSessionCookie($this, $secondLogin);
        freshHomfloRequest();

        $this->assertAuthenticatedAs($otherUser);

        $response = $this->getJson('/api/me');

        $response->assertOk();
        $response->assertJsonPath('data.user.id', $otherUser->id);
        $response->assertJsonPath('data.user.email', $otherUser->email);
    });

    test('full lifecycle register login me logout unauthenticated', function () {
        $this->postJson('/register', [
            'username' => 'lifecycleuser',
            'name' => 'Lifecycle User',
            'email' => 'lifecycle@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSuccessful();

        // Registration authenticates the user; log out before exercising login.
        $this->postJson('/logout')->assertNoContent();
        $this->assertGuest();
        freshHomfloRequest();

        $login = $this->postJson('/login', [
            'email' => 'lifecycle@example.com',
            'password' => 'password123',
        ])->assertOk();
        syncHomfloSessionCookie($this, $login);
        freshHomfloRequest();

        $meResponse = $this->getJson('/api/me');

        $meResponse->assertOk();
        $meResponse->assertJsonPath('data.user.email', 'lifecycle@example.com');
        $meResponse->assertJsonPath('data.onboarding.required', true);
        freshHomfloRequest();

        $logout = $this->postJson('/logout');
        $logout->assertNoContent();
        syncHomfloSessionCookie($this, $logout);
        freshHomfloRequest();

        $this->getJson('/api/me')->assertUnauthorized();
    });
});
