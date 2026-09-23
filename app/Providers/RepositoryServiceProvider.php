<?php

namespace App\Providers;

use App\Repositories\Contracts\HouseholdInvitationRepositoryInterface;
use App\Repositories\Contracts\HouseholdMemberRepositoryInterface;
use App\Repositories\Contracts\HouseholdRepositoryInterface;
use App\Repositories\Contracts\OAuthAccountRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\HouseholdInvitationRepository;
use App\Repositories\HouseholdMemberRepository;
use App\Repositories\HouseholdRepository;
use App\Repositories\OAuthAccountRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(
            UserRepositoryInterface::class,
            UserRepository::class,
        );
        $this->app->bind(
            OAuthAccountRepositoryInterface::class,
            OAuthAccountRepository::class,
        );
        $this->app->bind(
            HouseholdMemberRepositoryInterface::class,
            HouseholdMemberRepository::class,
        );
        $this->app->bind(
            HouseholdRepositoryInterface::class,
            HouseholdRepository::class,
        );
        $this->app->bind(
            HouseholdInvitationRepositoryInterface::class,
            HouseholdInvitationRepository::class,
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
