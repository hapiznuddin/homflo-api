<?php

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

function prRequestLink(object $testCase, string $email): ?string
{
    $token = null;

    $testCase->postJson('/api/password/forgot', ['email' => $email])->assertOk();

    Notification::assertSentTo(
        User::where('email', $email)->firstOrFail(),
        ResetPassword::class,
        function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        }
    );

    return $token;
}

describe('Forgot password', function () {
    test('existing email can request password reset', function () {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->postJson('/api/password/forgot', ['email' => $user->email]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        Notification::assertSentTo($user, ResetPassword::class);
    });

    test('unknown email returns the same response without notification', function () {
        Notification::fake();

        $known = $this->postJson('/api/password/forgot', ['email' => User::factory()->create()->email]);

        Notification::fake();

        $unknown = $this->postJson('/api/password/forgot', ['email' => 'nobody@example.com']);

        $unknown->assertOk();
        $unknown->assertJsonPath('success', true);
        expect($unknown->json('data'))->toEqual($known->json('data'));

        Notification::assertNothingSent();
    });

    test('missing email is rejected', function () {
        $response = $this->postJson('/api/password/forgot', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    });

    test('invalid email format is rejected', function () {
        $response = $this->postJson('/api/password/forgot', ['email' => 'not-an-email']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    });

    test('forgot request works without authentication', function () {
        Notification::fake();

        $user = User::factory()->create();

        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    });
});

describe('Reset password', function () {
    test('valid token resets password successfully', function () {
        Notification::fake();

        $user = User::factory()->create();
        $token = prRequestLink($this, $user->email);

        $response = $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    });

    test('new password is hashed and authenticates', function () {
        Notification::fake();

        $user = User::factory()->create();
        $token = prRequestLink($this, $user->email);

        $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();

        $user->refresh();

        expect($user->password)->not->toBe('newpassword123');
        expect(Hash::check('newpassword123', $user->password))->toBeTrue();

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(422);

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'newpassword123',
        ])->assertOk();
    });

    test('invalid token is rejected', function () {
        $user = User::factory()->create();

        $response = $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => 'invalid-token-value',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'INVALID_RESET_TOKEN');
    });

    test('expired token is rejected', function () {
        Notification::fake();

        $user = User::factory()->create();
        $token = prRequestLink($this, $user->email);

        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->update(['created_at' => now()->subHours(2)]);

        $response = $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INVALID_RESET_TOKEN');
    });

    test('wrong email token combination is rejected', function () {
        Notification::fake();

        $user = User::factory()->create();
        $other = User::factory()->create();
        $token = prRequestLink($this, $user->email);

        $response = $this->postJson('/api/password/reset', [
            'email' => $other->email,
            'token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INVALID_RESET_TOKEN');
    });

    test('password confirmation mismatch is rejected', function () {
        $user = User::factory()->create();

        $response = $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => 'some-token',
            'password' => 'newpassword123',
            'password_confirmation' => 'differentpassword',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    });

    test('weak password is rejected', function () {
        $user = User::factory()->create();

        $response = $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => 'some-token',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    });

    test('token cannot be reused after successful reset', function () {
        Notification::fake();

        $user = User::factory()->create();
        $token = prRequestLink($this, $user->email);

        $payload = [
            'email' => $user->email,
            'token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ];

        $this->postJson('/api/password/reset', $payload)->assertOk();

        $response = $this->postJson('/api/password/reset', $payload);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INVALID_RESET_TOKEN');
    });

    test('reset responses expose no token password or hash', function () {
        Notification::fake();

        $user = User::factory()->create();
        $token = prRequestLink($this, $user->email);

        $forgot = $this->postJson('/api/password/forgot', ['email' => $user->email]);

        $forgot->assertOk();
        $forgot->assertJsonMissing(['token', 'password']);
        expect($forgot->getContent())->not->toContain($token);

        $reset = $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $reset->assertOk();
        $reset->assertJsonMissing(['token', 'password', 'password_hash']);
        expect($reset->getContent())->not->toContain($token);
    });

    test('client user_id cannot influence which account is reset', function () {
        Notification::fake();

        $user = User::factory()->create();
        $other = User::factory()->create();
        $token = prRequestLink($this, $user->email);

        $response = $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
            'user_id' => $other->id,
        ]);

        $response->assertOk();

        expect(Hash::check('newpassword123', $user->fresh()->password))->toBeTrue();
        expect(Hash::check('newpassword123', $other->fresh()->password))->toBeFalse();
    });

    test('reset token is stored hashed in database', function () {
        Notification::fake();

        $user = User::factory()->create();
        $token = prRequestLink($this, $user->email);

        $stored = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');

        expect($stored)->not->toBeNull()
            ->and($stored)->not->toBe($token)
            ->and(Hash::check($token, $stored))->toBeTrue();
    });

    test('no personal access token is created by reset flow', function () {
        Notification::fake();

        $user = User::factory()->create();
        $token = prRequestLink($this, $user->email);

        $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();

        expect(DB::table('personal_access_tokens')->count())->toBe(0);
    });

    test('household membership is unaffected by password reset', function () {
        Notification::fake();

        $owner = User::factory()->create();
        $household = Household::create(['name' => 'Test Home', 'created_by' => $owner->id]);
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
        $token = prRequestLink($this, $owner->email);

        $this->postJson('/api/password/reset', [
            'email' => $owner->email,
            'token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();

        $this->assertDatabaseHas('household_members', [
            'household_id' => $household->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $this->postJson('/login', [
            'email' => $owner->email,
            'password' => 'newpassword123',
        ])->assertOk();

        $this->getJson('/api/me')->assertJsonPath('data.household.id', $household->id);
    });
});

describe('Reset URL generation', function () {
    test('generated reset url uses frontend base with token and email', function () {
        $user = User::factory()->create();
        $token = 'test-reset-token-value';

        $url = (new ResetPassword($token))->toMail($user)->actionUrl;

        expect($url)->toStartWith(rtrim((string) config('app.frontend_url'), '/').'/reset-password/')
            ->and($url)->toContain($token)
            ->and($url)->toContain(urlencode($user->email));
    });

    test('generated reset url follows configured frontend url', function () {
        config()->set('app.frontend_url', 'https://app.example.com');

        $user = User::factory()->create();

        $url = (new ResetPassword('another-token'))->toMail($user)->actionUrl;

        expect($url)->toStartWith('https://app.example.com/reset-password/');
    });

    test('url generation does not consume the broker token', function () {
        Notification::fake();

        $user = User::factory()->create();
        $token = prRequestLink($this, $user->email);

        $notificationUrl = (new ResetPassword($token))->toMail($user)->actionUrl;

        expect($notificationUrl)->toContain($token);

        $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();
    });
});
