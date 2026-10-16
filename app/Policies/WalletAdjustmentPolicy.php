<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Models\WalletAdjustment;

class WalletAdjustmentPolicy
{
    /**
     * Determine whether the user can list adjustments of a wallet.
     *
     * Any household member may read the audit trail.
     */
    public function viewAny(User $user, Household $household): bool
    {
        return $this->membership($user, $household->id) !== null;
    }

    /**
     * Determine whether the user can record an adjustment.
     *
     * Owner-only: adjustments rewrite derived balances outside the
     * transaction ledger.
     */
    public function create(User $user, Household $household): bool
    {
        return $this->owner($user, $household->id) !== null;
    }

    /**
     * Adjustments are immutable: there is deliberately no update or
     * delete ability. Corrections are recorded as new adjustments.
     */
    public function update(User $user, WalletAdjustment $adjustment): bool
    {
        return false;
    }

    public function delete(User $user, WalletAdjustment $adjustment): bool
    {
        return false;
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
