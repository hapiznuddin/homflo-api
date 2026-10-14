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
     * Any household member (owner or member) may record. There is no
     * owner-only finance rule in the project documents; this needs
     * product confirmation if stricter roles are wanted.
     */
    public function create(User $user, Household $household): bool
    {
        return $this->membership($user, $household->id) !== null;
    }

    /**
     * Determine whether the user can update the transaction.
     *
     * Any member of the transaction's household may update.
     */
    public function update(User $user, Transaction $transaction): bool
    {
        return $this->membership($user, $transaction->household_id) !== null;
    }

    /**
     * Determine whether the user can delete the transaction.
     *
     * Any member of the transaction's household may delete. No retention
     * workflow exists yet; hard delete matches the schema.
     */
    public function delete(User $user, Transaction $transaction): bool
    {
        return $this->membership($user, $transaction->household_id) !== null;
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
}
