<?php

namespace App\Providers;

use App\Repositories\AssetRepository;
use App\Repositories\BillRepository;
use App\Repositories\BudgetRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Repositories\Contracts\BillRepositoryInterface;
use App\Repositories\Contracts\BudgetRepositoryInterface;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use App\Repositories\Contracts\HouseholdInvitationRepositoryInterface;
use App\Repositories\Contracts\HouseholdMemberRepositoryInterface;
use App\Repositories\Contracts\HouseholdRepositoryInterface;
use App\Repositories\Contracts\MaintenanceRepositoryInterface;
use App\Repositories\Contracts\OAuthAccountRepositoryInterface;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\WalletAdjustmentRepositoryInterface;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Repositories\HouseholdInvitationRepository;
use App\Repositories\HouseholdMemberRepository;
use App\Repositories\HouseholdRepository;
use App\Repositories\MaintenanceRepository;
use App\Repositories\OAuthAccountRepository;
use App\Repositories\TransactionRepository;
use App\Repositories\UserRepository;
use App\Repositories\WalletAdjustmentRepository;
use App\Repositories\WalletRepository;
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
        $this->app->bind(
            AssetRepositoryInterface::class,
            AssetRepository::class,
        );
        $this->app->bind(
            MaintenanceRepositoryInterface::class,
            MaintenanceRepository::class,
        );
        $this->app->bind(
            TransactionRepositoryInterface::class,
            TransactionRepository::class,
        );
        $this->app->bind(
            CategoryRepositoryInterface::class,
            CategoryRepository::class,
        );
        $this->app->bind(
            BudgetRepositoryInterface::class,
            BudgetRepository::class,
        );
        $this->app->bind(
            BillRepositoryInterface::class,
            BillRepository::class,
        );
        $this->app->bind(
            WalletRepositoryInterface::class,
            WalletRepository::class,
        );
        $this->app->bind(
            WalletAdjustmentRepositoryInterface::class,
            WalletAdjustmentRepository::class,
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
