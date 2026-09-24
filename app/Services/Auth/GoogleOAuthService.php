<?php

namespace App\Services\Auth;

use App\Exceptions\GoogleAuthenticationException;
use App\Models\User;
use App\Repositories\Contracts\OAuthAccountRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class GoogleOAuthService
{
    private const PROVIDER = 'google';

    public function __construct(
        private readonly OAuthAccountRepositoryInterface $oauthAccounts,
        private readonly UserRepositoryInterface $users,
    ) {}

    /**
     * Resolve the local user for a verified Google identity.
     *
     * Existing link → that user. Matching local email → link then that
     * user. Otherwise create a new user plus link, atomically.
     *
     * @throws GoogleAuthenticationException
     */
    public function resolveUser(SocialiteUser $socialiteUser): User
    {
        $providerUserId = (string) $socialiteUser->getId();
        $email = strtolower(trim((string) $socialiteUser->getEmail()));

        if ($providerUserId === '' || $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new GoogleAuthenticationException;
        }

        $linked = $this->oauthAccounts->findByProvider(self::PROVIDER, $providerUserId);

        if ($linked !== null && $linked->user !== null) {
            return $linked->user;
        }

        $user = $this->users->findByEmail($email);

        if ($user !== null) {
            $this->linkAccount($user, $providerUserId);

            return $user;
        }

        try {
            return DB::transaction(function () use ($socialiteUser, $email, $providerUserId) {
                $user = $this->users->create([
                    'username' => $this->deriveUsername($socialiteUser, $email),
                    'name' => $socialiteUser->getName()
                        ?? $socialiteUser->getNickname()
                        ?? Str::before($email, '@'),
                    'email' => $email,
                    'password' => Hash::make(Str::random(64)),
                ]);

                $this->oauthAccounts->create([
                    'user_id' => $user->id,
                    'provider' => self::PROVIDER,
                    'provider_user_id' => $providerUserId,
                ]);

                return $user;
            });
        } catch (QueryException $exception) {
            $resolved = $this->resolveRace($providerUserId);

            if (strtolower(trim($resolved->email)) !== $email) {
                throw new GoogleAuthenticationException;
            }

            return $resolved;
        }
    }

    /**
     * Link a Google identity to an existing user.
     *
     * @throws GoogleAuthenticationException
     */
    private function linkAccount(User $user, string $providerUserId): void
    {
        try {
            $this->oauthAccounts->create([
                'user_id' => $user->id,
                'provider' => self::PROVIDER,
                'provider_user_id' => $providerUserId,
            ]);
        } catch (QueryException $exception) {
            $resolved = $this->resolveRace($providerUserId);

            if ($resolved->id !== $user->id) {
                throw new GoogleAuthenticationException;
            }
        }
    }

    /**
     * Re-resolve after a unique-constraint race so concurrent callbacks
     * never produce duplicate users or links.
     *
     * @throws GoogleAuthenticationException
     */
    private function resolveRace(string $providerUserId): User
    {
        $existing = $this->oauthAccounts->findByProvider(self::PROVIDER, $providerUserId);

        if ($existing !== null && $existing->user !== null) {
            return $existing->user;
        }

        throw new GoogleAuthenticationException;
    }

    private function deriveUsername(SocialiteUser $socialiteUser, string $email): string
    {
        $base = Str::slug(
            (string) ($socialiteUser->getNickname() ?? $socialiteUser->getName() ?? Str::before($email, '@')),
            '-'
        );

        $base = substr((string) preg_replace('/[^A-Za-z0-9_\-]/', '', $base) ?: 'user', 0, 30);

        $username = $base;
        $suffix = 0;

        while ($this->users->findByUsername($username) !== null) {
            $suffix++;
            $username = substr($base, 0, 30 - strlen((string) $suffix) - 1).'-'.$suffix;
        }

        return $username;
    }
}
