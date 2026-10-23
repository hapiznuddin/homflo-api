<?php

namespace App\Providers;

use App\Models\Asset;
use App\Models\Bill;
use App\Models\BillRecurrence;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\WalletAdjustment;
use App\Policies\AssetPolicy;
use App\Policies\BillPolicy;
use App\Policies\BillRecurrencePolicy;
use App\Policies\BudgetPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\HouseholdPolicy;
use App\Policies\TransactionPolicy;
use App\Policies\WalletAdjustmentPolicy;
use App\Policies\WalletPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Household::class, HouseholdPolicy::class);
        Gate::policy(Transaction::class, TransactionPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Budget::class, BudgetPolicy::class);
        Gate::policy(Wallet::class, WalletPolicy::class);
        Gate::policy(WalletAdjustment::class, WalletAdjustmentPolicy::class);
        Gate::policy(Asset::class, AssetPolicy::class);
        Gate::policy(Bill::class, BillPolicy::class);
        Gate::policy(BillRecurrence::class, BillRecurrencePolicy::class);

        VerifyEmail::createUrlUsing(function (object $notifiable) {
            return URL::temporarySignedRoute(
                'api.verification.verify',
                now()->addMinutes(60),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ]
            );
        });

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return rtrim((string) config('app.frontend_url'), '/')
                .'/reset-password/'.$token
                .'?'.http_build_query(['email' => $notifiable->getEmailForPasswordReset()]);
        });
    }
}
