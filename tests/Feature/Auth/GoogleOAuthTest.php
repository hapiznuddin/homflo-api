<?php

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\OAuthAccount;
use App\Models\User;
use App\Repositories\Contracts\OAuthAccountRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

function googleUser(array $overrides = []): SocialiteUser
{
    $user = new SocialiteUser;
    $user->id = $overrides['id'] ?? 'google-123';
    $user->nickname = $overrides['nickname'] ?? 'googler';
    $user->name = $overrides['name'] ?? 'Google Rr';
    $user->email = array_key_exists('email', $overrides) ? $overrides['email'] : 'googler@example.com';
    $user->avatar = $overrides['avatar'] ?? null;

    return $user;
}

function mockGoogleCallback(SocialiteUser $socialiteUser): void
{
    $driver = Mockery::mock();
    $driver->shouldReceive('user')->andReturn($socialiteUser);
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
}

describe('Google OAuth', function () {
    test('redirect route exists and uses google driver', function () {
        $driver = Mockery::mock();
        $driver->shouldReceive('redirect')->once()->andReturn(redirect('https://accounts.google.com/o/oauth2/auth?client_id=x'));
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);

        $response = $this->get('/api/auth/google/redirect');

        $response->assertRedirect('https://accounts.google.com/o/oauth2/auth?client_id=x');
    });

    test('callback route exists', function () {
        mockGoogleCallback(googleUser());

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect();
    });

    test('callback authenticates a new oauth user', function () {
        mockGoogleCallback(googleUser());

        $this->get('/api/auth/google/callback')->assertRedirect();

        $this->assertAuthenticated();
    });

    test('new oauth user gets a local user', function () {
        mockGoogleCallback(googleUser(['email' => 'brandnew@example.com']));

        $this->get('/api/auth/google/callback')->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'brandnew@example.com']);
    });

    test('new oauth user gets an oauth account', function () {
        mockGoogleCallback(googleUser(['id' => 'google-999', 'email' => 'linked@example.com']));

        $this->get('/api/auth/google/callback')->assertRedirect();

        $this->assertDatabaseHas('oauth_accounts', [
            'provider' => 'google',
            'provider_user_id' => 'google-999',
        ]);
    });

    test('oauth account identity is stored with user link', function () {
        mockGoogleCallback(googleUser(['id' => 'google-111', 'email' => 'stored@example.com']));

        $this->get('/api/auth/google/callback')->assertRedirect();

        $user = User::where('email', 'stored@example.com')->firstOrFail();
        $account = OAuthAccount::where('provider', 'google')->where('provider_user_id', 'google-111')->firstOrFail();

        expect($account->user_id)->toBe($user->id);
    });

    test('existing oauth account logs into existing user', function () {
        $user = User::factory()->create();
        OAuthAccount::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-222',
        ]);

        mockGoogleCallback(googleUser(['id' => 'google-222', 'email' => 'different@example.com']));

        $this->get('/api/auth/google/callback')->assertRedirect();

        $this->assertAuthenticatedAs($user);
        expect(User::count())->toBe(1);
        expect(OAuthAccount::count())->toBe(1);
    });

    test('existing local email links google account without duplicate user', function () {
        $user = User::factory()->create(['email' => 'local@example.com']);

        mockGoogleCallback(googleUser(['id' => 'google-333', 'email' => 'local@example.com']));

        $this->get('/api/auth/google/callback')->assertRedirect();

        $this->assertAuthenticatedAs($user);
        expect(User::count())->toBe(1);

        $this->assertDatabaseHas('oauth_accounts', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-333',
        ]);
    });

    test('existing username is preserved on linking', function () {
        $user = User::factory()->create(['username' => 'keepme', 'email' => 'keepme@example.com']);

        mockGoogleCallback(googleUser(['id' => 'google-444', 'email' => 'keepme@example.com', 'nickname' => 'othernick']));

        $this->get('/api/auth/google/callback')->assertRedirect();

        expect($user->fresh()->username)->toBe('keepme');
    });

    test('oauth linking does not change existing password', function () {
        $user = User::factory()->create(['email' => 'samepass@example.com']);
        $hash = $user->password;

        mockGoogleCallback(googleUser(['id' => 'google-555', 'email' => 'samepass@example.com']));

        $this->get('/api/auth/google/callback')->assertRedirect();

        expect($user->fresh()->password)->toBe($hash);
    });

    test('oauth created user password is unusable and not exposed', function () {
        mockGoogleCallback(googleUser(['email' => 'nopass@example.com']));

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect();
        expect($response->getContent())->not->toContain('password');

        $user = User::where('email', 'nopass@example.com')->firstOrFail();

        expect(Hash::check('password', $user->password))->toBeFalse();
    });

    test('missing provider email fails safely', function () {
        mockGoogleCallback(googleUser(['email' => null]));

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect(rtrim((string) config('app.frontend_url'), '/').'/login?error=oauth_failed');
        expect(User::count())->toBe(0);
        expect(OAuthAccount::count())->toBe(0);
    });

    test('invalid state failure is handled safely', function () {
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->andThrow(new InvalidStateException);
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect(rtrim((string) config('app.frontend_url'), '/').'/login?error=oauth_failed');
        expect($response->getContent())->not->toContain('InvalidState');
    });

    test('duplicate oauth identity does not create duplicate records', function () {
        $socialiteUser = googleUser(['id' => 'google-666', 'email' => 'dup@example.com']);
        mockGoogleCallback($socialiteUser);

        $this->get('/api/auth/google/callback')->assertRedirect();
        $this->get('/api/auth/google/callback')->assertRedirect();

        expect(User::where('email', 'dup@example.com')->count())->toBe(1);
        expect(OAuthAccount::where('provider_user_id', 'google-666')->count())->toBe(1);
    });

    test('cross-user oauth identity cannot be silently reassigned', function () {
        $userA = User::factory()->create(['email' => 'aaa@example.com']);
        OAuthAccount::create([
            'user_id' => $userA->id,
            'provider' => 'google',
            'provider_user_id' => 'google-777',
        ]);
        $userB = User::factory()->create(['email' => 'bbb@example.com']);

        // Same Google identity, different email claim: identity wins, B untouched.
        mockGoogleCallback(googleUser(['id' => 'google-777', 'email' => 'bbb@example.com']));

        $this->get('/api/auth/google/callback')->assertRedirect();

        $this->assertAuthenticatedAs($userA);
        expect($userB->fresh()->email)->toBe('bbb@example.com');
        expect(OAuthAccount::where('provider_user_id', 'google-777')->count())->toBe(1);
        expect(OAuthAccount::where('provider_user_id', 'google-777')->first()->user_id)->toBe($userA->id);
    });

    test('no oauth access token is returned in response', function () {
        mockGoogleCallback(googleUser(['email' => 'notoken@example.com']));

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect();
        expect($response->getContent())->not->toContain('access_token')
            ->and($response->headers->get('Location'))->not->toContain('access_token');
    });

    test('no refresh token is returned', function () {
        mockGoogleCallback(googleUser(['email' => 'norefresh@example.com']));

        $response = $this->get('/api/auth/google/callback');

        expect($response->headers->get('Location'))->not->toContain('refresh_token');
    });

    test('no pat or jwt is created', function () {
        mockGoogleCallback(googleUser(['email' => 'nopat@example.com']));

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect();
        expect(DB::table('personal_access_tokens')->count())->toBe(0);
        expect($response->headers->get('Location'))->not->toContain('token=');
    });

    test('session is authenticated after callback', function () {
        mockGoogleCallback(googleUser(['email' => 'sess@example.com']));

        $this->get('/api/auth/google/callback')->assertRedirect();

        $this->assertAuthenticated();
    });

    test('me works after oauth callback', function () {
        mockGoogleCallback(googleUser(['email' => 'meafter@example.com']));

        $this->get('/api/auth/google/callback')->assertRedirect();

        $response = $this->getJson('/api/me');

        $response->assertOk();
        $response->assertJsonPath('data.user.email', 'meafter@example.com');
    });

    test('new oauth user has onboarding required state', function () {
        mockGoogleCallback(googleUser(['email' => 'onboard@example.com']));

        $this->get('/api/auth/google/callback')->assertRedirect();

        $response = $this->getJson('/api/me');

        $response->assertJsonPath('data.household', null);
        $response->assertJsonPath('data.onboarding.required', true);
    });

    test('existing household membership remains intact on oauth login', function () {
        $user = User::factory()->create(['email' => 'homed@example.com']);
        $household = Household::create(['name' => 'Test Home', 'created_by' => $user->id]);
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        mockGoogleCallback(googleUser(['id' => 'google-888', 'email' => 'homed@example.com']));

        $this->get('/api/auth/google/callback')->assertRedirect();

        $response = $this->getJson('/api/me');

        $response->assertJsonPath('data.household.id', $household->id);
        $response->assertJsonPath('data.household.role', 'owner');
        expect(Household::count())->toBe(1);
    });

    test('oauth flow does not automatically create a household', function () {
        mockGoogleCallback(googleUser(['email' => 'nohouse@example.com']));

        $this->get('/api/auth/google/callback')->assertRedirect();

        expect(Household::count())->toBe(0);
        expect(HouseholdMember::count())->toBe(0);
    });

    test('client cannot control redirect destination', function () {
        config()->set('app.frontend_url', 'https://app.example.com');

        mockGoogleCallback(googleUser(['email' => 'redir@example.com']));

        $response = $this->get('/api/auth/google/callback?redirect=https://attacker.example');

        $response->assertRedirect('https://app.example.com/auth/callback');
    });

    test('email normalization links case variants without duplicates', function () {
        $user = User::factory()->create(['email' => 'CamelCase@Example.com']);

        mockGoogleCallback(googleUser(['id' => 'google-999', 'email' => 'camelcase@example.com']));

        $this->get('/api/auth/google/callback')->assertRedirect();

        $this->assertAuthenticatedAs($user);
        expect(User::count())->toBe(1);
    });

    test('oauth linking is transactional without orphans', function () {
        $inner = app(OAuthAccountRepositoryInterface::class);
        $failing = new class($inner) implements OAuthAccountRepositoryInterface
        {
            public function __construct(private readonly OAuthAccountRepositoryInterface $inner) {}

            public function findByProvider(string $provider, string $providerUserId): ?OAuthAccount
            {
                return $this->inner->findByProvider($provider, $providerUserId);
            }

            public function create(array $data): OAuthAccount
            {
                throw new RuntimeException('link failed');
            }
        };
        app()->instance(OAuthAccountRepositoryInterface::class, $failing);

        mockGoogleCallback(googleUser(['id' => 'google-000', 'email' => 'orphan@example.com']));

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect(rtrim((string) config('app.frontend_url'), '/').'/login?error=oauth_failed');
        expect(User::where('email', 'orphan@example.com')->exists())->toBeFalse();
        expect(OAuthAccount::where('provider_user_id', 'google-000')->exists())->toBeFalse();
    });

    test('provider failure does not leak raw exception', function () {
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->andThrow(new Exception('client_secret=supersecret'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect(rtrim((string) config('app.frontend_url'), '/').'/login?error=oauth_failed');
        expect($response->getContent())->not->toContain('supersecret');
        expect($response->exception)->toBeNull();
    });

    test('callback ignores user_id household_id role payload', function () {
        $user = User::factory()->create(['email' => 'payload@example.com']);

        mockGoogleCallback(googleUser(['id' => 'google-121', 'email' => 'payload@example.com']));

        $response = $this->get('/api/auth/google/callback?'.http_build_query([
            'user_id' => 'someone-else',
            'household_id' => 'another-household',
            'role' => 'owner',
        ]));

        $response->assertRedirect();

        $this->assertAuthenticatedAs($user);
        expect(HouseholdMember::where('user_id', $user->id)->count())->toBe(0);
    });

    test('oauth does not reveal client secret', function () {
        config()->set('services.google.client_secret', 'test-secret-value');

        mockGoogleCallback(googleUser(['email' => 'nosecret@example.com']));

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect();
        expect($response->getContent())->not->toContain('test-secret-value');
    });
});
