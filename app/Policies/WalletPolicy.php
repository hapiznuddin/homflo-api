<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Models\Wallet;

class WalletPolicy
{
    /**
     * Determine whether the user can list wallets.
     *
     * Any household member (owner or member) may read wallet metadata
     * and derived balances.
     */
    public function viewAny(User $user, Household $household): bool
    {
        return $this->membership($user, $household->id) !== null;
    }

    /**
     * Determine whether the user can view the wallet.
     *
     * Any member of the wallet's household may view, including wallets
     * that were deactivated: history stays readable.
     */
    public function view(User $user, Wallet $wallet): bool
    {
        return $this->membership($user, $wallet->household_id) !== null;
    }

    /**
     * Determine whether the user can create a wallet.
     *
     * Owner-only: wallets define where shared money lives.
     */
    public function create(User $user, Household $household): bool
    {
        return $this->owner($user, $household->id) !== null;
    }

    /**
     * Determine whether the user can update wallet metadata.
     *
     * Owner-only. Activation state is never writable here; use the
     * dedicated deactivate path instead.
     */
    public function update(User $user, Wallet $wallet): bool
    {
        return $this->owner($user, $wallet->household_id) !== null;
    }

    /**
     * Determine whether the user can deactivate a wallet.
     *
     * Owner-only. Deactivation never deletes history.
     */
    public function deactivate(User $user, Wallet $wallet): bool
    {
        return $this->owner($user, $wallet->household_id) !== null;
    }

    /**
     * Determine whether the user can delete a wallet.
     *
     * Owner-only. The service additionally refuses wallets that carry
     * any financial history.
     */
    public function delete(User $user, Wallet $wallet): bool
    {
        return $this->owner($user, $wallet->household_id) !== null;
    }

    /**
     * Resolve membership from server-side data only.
     *
     * Never trust user_id, household_id, or role from client input.
     */
    private function membership(User $user, Household|string $household): ?HouseholdMember
    {
        return HouseholdMember::query()
            ->where('user_id', $user->id)
            ->where('household_id', $household instanceof Household ? $household->id : $household)
            ->first();
    }

    /**
     * Resolve an owner membership from server-side data only. Finance
     * writes are owner-only; reads stay available to every member.
     */
    private function owner(User $user, Household|string $household): ?HouseholdMember
    {
        $membership = $this->membership($user, $household);

        if ($membership === null || $membership->role !== 'owner') {
            return null;
        }

        return $membership;
    }
}
