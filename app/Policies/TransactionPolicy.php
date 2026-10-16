<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    /**
     * Determine whether the user can list transactions.
     *
     * Any household member (owner or member) may read the shared ledger.
     */
    public function viewAny(User $user, Household $household): bool
    {
        return $this->membership($user, $household->id) !== null;
    }

    /**
     * Determine whether the user can view the transaction.
     *
     * Any member of the transaction's household may view.
     */
    public function view(User $user, Transaction $transaction): bool
    {
        return $this->membership($user, $transaction->household_id) !== null;
    }

    /**
     * Determine whether the user can record a transaction.
     *
     * Owner-only: recording mutates the shared ledger and wallet balances.
     */
    public function create(User $user, Household $household): bool
    {
        return $this->owner($user, $household->id) !== null;
    }

    /**
     * Determine whether the user can update the transaction.
     *
     * Owner-only: edits re-validate every affected wallet balance.
     */
    public function update(User $user, Transaction $transaction): bool
    {
        return $this->owner($user, $transaction->household_id) !== null;
    }

    /**
     * Determine whether the user can delete the transaction.
     *
     * Owner-only. No retention workflow exists yet; hard delete matches
     * the schema, after balance validation.
     */
    public function delete(User $user, Transaction $transaction): bool
    {
        return $this->owner($user, $transaction->household_id) !== null;
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
