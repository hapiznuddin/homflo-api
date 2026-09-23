<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

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

        $response = $this->actingAs($other)->getJson(evSignedUrl($user));

        $response->assertForbidden();
        $response->assertJsonPath('error.code', 'FORBIDDEN');

        expect($user->fresh()->email_verified_at)->toBeNull()
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

    test('guest cannot verify email', function () {
        $user = User::factory()->unverified()->create();

        $this->getJson(evSignedUrl($user))->assertUnauthorized();

        expect($user->fresh()->email_verified_at)->toBeNull();
    });
});
