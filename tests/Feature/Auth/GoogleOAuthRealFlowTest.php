<?php

use App\Models\User;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Laravel\Socialite\Facades\Socialite;

function googleRealFlowFake(string $sub, string $email, string $name = 'Real Flow'): void
{
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'expires_in' => 3600,
        ])),
        new Response(200, [], json_encode([
            'sub' => $sub,
            'name' => $name,
            'email' => $email,
            'picture' => null,
        ])),
    ]);

    config()->set('services.google.guzzle', ['handler' => HandlerStack::create($mock)]);
    config()->set('services.google.client_id', 'test-client-id');
    config()->set('services.google.client_secret', 'test-client-secret');
}

function googleFollowRedirect(object $testCase): array
{
    $testCase->withCredentials();

    $redirect = $testCase->get('/api/auth/google/redirect');

    $redirect->assertRedirect();

    $location = (string) $redirect->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $sessionName = (string) config('session.cookie');

    foreach ($redirect->headers->getCookies() as $cookie) {
        if ($cookie->getName() === $sessionName) {
            $testCase->withUnencryptedCookie($sessionName, (string) $cookie->getValue());
        }
    }

    // A new HTTP request gets a fresh container: drop the cached driver
    // holding the previous request instance.
    Socialite::forgetDrivers();
    app('auth')->forgetGuards();

    return $query;
}

describe('Google OAuth real browser flow', function () {
    test('full redirect callback login flow works end to end', function () {
        googleRealFlowFake('google-real-1', 'realflow@example.com');

        $query = googleFollowRedirect($this);

        expect($query['state'] ?? null)->not->toBeEmpty();

        $callback = $this->get('/api/auth/google/callback?'.http_build_query([
            'state' => $query['state'],
            'code' => 'fake-authorization-code',
        ]));

        $callback->assertRedirect(rtrim((string) config('app.frontend_url'), '/').'/auth/callback');

        $this->assertAuthenticated();

        $me = $this->getJson('/api/me');

        $me->assertOk();
        $me->assertJsonPath('data.user.email', 'realflow@example.com');
        $me->assertJsonPath('data.onboarding.required', true);
    });

    test('replaying the same callback fails safely because state is single use', function () {
        googleRealFlowFake('google-real-3', 'replay@example.com');

        $query = googleFollowRedirect($this);

        $callbackQuery = http_build_query([
            'state' => $query['state'],
            'code' => 'fake-authorization-code',
        ]);

        $this->get('/api/auth/google/callback?'.$callbackQuery)
            ->assertRedirect(rtrim((string) config('app.frontend_url'), '/').'/auth/callback');

        // Replaying the identical callback must not authenticate again:
        // Socialite consumes the session state on first use. A new HTTP
        // request resolves a fresh driver, like production containers do.
        Socialite::forgetDrivers();
        app('auth')->forgetGuards();

        $replay = $this->get('/api/auth/google/callback?'.$callbackQuery);

        $replay->assertRedirect(rtrim((string) config('app.frontend_url'), '/').'/login?error=oauth_failed');

        expect(User::where('email', 'replay@example.com')->count())->toBe(1);
    });

    test('callback rejects tampered state in real flow', function () {
        googleRealFlowFake('google-real-2', 'tampered@example.com');

        $query = googleFollowRedirect($this);

        $callback = $this->get('/api/auth/google/callback?'.http_build_query([
            'state' => ($query['state'] ?? 'x').'tampered',
            'code' => 'fake-authorization-code',
        ]));

        $callback->assertRedirect(rtrim((string) config('app.frontend_url'), '/').'/login?error=oauth_failed');

        $this->assertGuest();
        expect(User::where('email', 'tampered@example.com')->exists())->toBeFalse();
    });
});
