<?php

namespace App\Repositories;

use App\Models\OAuthAccount;
use App\Repositories\Contracts\OAuthAccountRepositoryInterface;

class OAuthAccountRepository implements OAuthAccountRepositoryInterface
{
    /**
     * Create a new class instance.
     */
    public function findByProvider(
        string $provider,
        string $providerUserId
    ): ?OAuthAccount {
        return OAuthAccount::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();
    }

    public function create(array $data): OAuthAccount
    {
        return OAuthAccount::query()->create($data);
    }
}
