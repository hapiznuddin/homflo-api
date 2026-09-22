<?php

namespace App\Repositories\Contracts;

use App\Models\OAuthAccount;

interface OAuthAccountRepositoryInterface
{
    public function findByProvider(
        string $provider,
        string $providerUserId
    ): ?OAuthAccount;

    public function create(array $data): OAuthAccount;
}
