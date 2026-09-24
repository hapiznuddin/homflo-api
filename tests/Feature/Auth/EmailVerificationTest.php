<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

function evSignedUrl(User $user, ?string $hash = null, ?DateTimeInterface $expires = null): string
{
    return URL::temporarySignedRoute(
        'api.verification.verify',
        $expires ?? now()->addMinutes(60),
        [
            'id' => $user->getKey(),
            'hash' => $hash ?? sha1($user->getEmailForVerification()),
        ]
    );
}

describe('Email verification', function () {
    test('newly registered user has unverified email state', function () {
        $this->postJson('/register', [
            'username' => 'verifyuser',
            'name' => 'Verify User',
            'email' => 'verify@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'email' => 'verify@example.com',
            'email_verified_at' => null,
        ]);
    });

    test('registration does NOT send verification notification', function () {
        Notification::fake();

        $this->postJson('/register', [
            'username' => 'noautouser',
            'name' => 'No Auto User',
            'email' => 'noauto@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSuccessful();

        Notification::assertNothingSent();
    });

    test('newly registered user can login normally while unverified', function () {
        $this->postJson('/register', [
            'username' => 'plainlogin',
            'name' => 'Plain Login',
            'email' => 'plainlogin@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSuccessful();

        $this->postJson('/logout')->assertNoContent();

        $this->postJson('/login', [
            'email' => 'plainlogin@example.com',
            'password' => 'password123',
        ])->assertOk();
    });

    test('me exposes unverified state', function () {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->getJson('/api/me');

        $response->assertOk();
        $response->assertJsonPath('data.user.email_verified_at', null);
    });

    test('me exposes verified state', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/me');

        $response->assertOk();
        expect($response->json('data.user.email_verified_at'))->not->toBeNull();
    });

    test('authenticated unverified user can request verification', function () {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->postJson('/api/email/verification-notification');

        $response->assertStatus(202);
        $response->assertJsonPath('success', true);

        Notification::assertSentTo($user, VerifyEmail::class);
    });

    test('unauthenticated verification request is denied', function () {
        $this->postJson('/api/email/verification-notification')->assertUnauthorized();
    });

    test('already verified user is handled safely without notification', function () {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/email/verification-notification');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.verified', true);

        Notification::assertNothingSent();
    });

    test('client user_id cannot target another user for verification', function () {
        Notification::fake();

        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->postJson('/api/email/verification-notification', [
            'user_id' => $other->id,
            'email' => $other->email,
        ]);

        $response->assertStatus(202);

        Notification::assertSentTo($user, VerifyEmail::class);
        Notification::assertNotSentTo($other, VerifyEmail::class);
    });

    test('valid verification marks email as verified', function () {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->getJson(evSignedUrl($user));

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.verified', true);

        expect($user->fresh()->email_verified_at)->not->toBeNull();
    });

    test('verification cannot verify another user', function () {
        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();

        // A valid link for $user verifies $user even when another user is
        // logged in; the logged-in account itself must stay unverified.
        $response = $this->actingAs($other)->getJson(evSignedUrl($user));

        $response->assertOk();
        $response->assertJsonPath('success', true);

        expect($user->fresh()->email_verified_at)->not->toBeNull()
            ->and($other->fresh()->email_verified_at)->toBeNull();
    });

    test('mismatched id and hash are rejected with envelope', function () {
        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'api.verification.verify',
            now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1($other->email)]
        );

        $response = $this->actingAs($user)->getJson($url);

        $response->assertForbidden();
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    });

    test('invalid signature is rejected', function () {
        $user = User::factory()->unverified()->create();

        $url = evSignedUrl($user).'tampered';

        $this->actingAs($user)->getJson($url)->assertForbidden();

        expect($user->fresh()->email_verified_at)->toBeNull();
    });

    test('expired verification link is rejected', function () {
        $user = User::factory()->unverified()->create();

        $url = evSignedUrl($user, null, now()->subMinutes(5));

        $this->actingAs($user)->getJson($url)->assertForbidden();

        expect($user->fresh()->email_verified_at)->toBeNull();
    });

    test('repeated verification is handled safely', function () {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->getJson(evSignedUrl($user))->assertOk();

        $firstVerifiedAt = $user->fresh()->email_verified_at;

        $response = $this->actingAs($user)->getJson(evSignedUrl($user));

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.verified', true);

        expect($user->fresh()->email_verified_at)->toEqual($firstVerifiedAt);
    });

    test('verification responses expose no secret token', function () {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $resend = $this->actingAs($user)->postJson('/api/email/verification-notification');

        $resend->assertStatus(202);
        $resend->assertJsonMissing(['token', 'token_hash']);

        $verify = $this->actingAs($user)->getJson(evSignedUrl($user));

        $verify->assertOk();
        $verify->assertJsonMissing(['token', 'token_hash']);
    });

    test('guest with tampered signature is still rejected', function () {
        $user = User::factory()->unverified()->create();

        $this->getJson(evSignedUrl($user).'tampered')->assertForbidden();

        expect($user->fresh()->email_verified_at)->toBeNull();
    });
});

describe('Email verification without session', function () {
    test('verification succeeds WITHOUT authenticated Sanctum user', function () {
        $user = User::factory()->unverified()->create();

        $response = $this->getJson(evSignedUrl($user));

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.verified', true);

        expect($user->fresh()->email_verified_at)->not->toBeNull();
    });

    test('verification URL for user A cannot verify user B', function () {
        $userA = User::factory()->unverified()->create();
        $userB = User::factory()->unverified()->create();

        $response = $this->getJson(evSignedUrl($userA));

        $response->assertOk();

        expect($userA->fresh()->email_verified_at)->not->toBeNull()
            ->and($userB->fresh()->email_verified_at)->toBeNull();
    });

    test('wrong hash with valid signature is rejected without session', function () {
        $userA = User::factory()->unverified()->create();
        $userB = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'api.verification.verify',
            now()->addMinutes(60),
            ['id' => $userA->getKey(), 'hash' => sha1($userB->email)]
        );

        $response = $this->getJson($url);

        $response->assertForbidden();
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'FORBIDDEN');

        expect($userA->fresh()->email_verified_at)->toBeNull();
    });

    test('invalid signature is rejected without session', function () {
        $user = User::factory()->unverified()->create();

        $this->getJson(evSignedUrl($user).'tampered')->assertForbidden();

        expect($user->fresh()->email_verified_at)->toBeNull();
    });

    test('expired signature is rejected without session', function () {
        $user = User::factory()->unverified()->create();

        $this->getJson(evSignedUrl($user, null, now()->subMinutes(5)))->assertForbidden();

        expect($user->fresh()->email_verified_at)->toBeNull();
    });

    test('nonexistent user is rejected without leaking data', function () {
        $url = URL::temporarySignedRoute(
            'api.verification.verify',
            now()->addMinutes(60),
            ['id' => (string) Str::ulid(), 'hash' => sha1('nobody@example.com')]
        );

        $response = $this->getJson($url);

        $response->assertNotFound();
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'USER_NOT_FOUND');
    });

    test('verification is idempotent without session', function () {
        $user = User::factory()->unverified()->create();

        $this->getJson(evSignedUrl($user))->assertOk();

        $firstVerifiedAt = $user->fresh()->email_verified_at;

        $response = $this->getJson(evSignedUrl($user));

        $response->assertOk();
        $response->assertJsonPath('data.verified', true);

        expect($user->fresh()->email_verified_at)->toEqual($firstVerifiedAt);
    });
});
