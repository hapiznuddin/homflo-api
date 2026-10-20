<?php

namespace App\Policies;

use App\Models\Bill;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;

class BillPolicy
{
    /**
     * Determine whether the user can list bills.
     *
     * Any household member (owner or member) may read the shared bills,
     * like the other finance reads.
     */
    public function viewAny(User $user, Household $household): bool
    {
        return $this->membership($user, $household->id) !== null;
    }

    /**
     * Determine whether the user can view the bill.
     *
     * Any member of the bill's household may view. Archived and paid
     * bills stay readable.
     */
    public function view(User $user, Bill $bill): bool
    {
        return $this->membership($user, $bill->household_id) !== null;
    }

    /**
     * Determine whether the user can create a bill.
     *
     * Owner-only: bills commit shared household money.
     */
    public function create(User $user, Household $household): bool
    {
        return $this->owner($user, $household->id) !== null;
    }

    /**
     * Determine whether the user can update the bill.
     *
     * Owner-only. Paid bills are refused by the service lifecycle before
     * any field is applied; is_active and the recurrence key are
     * prohibited at the request layer (archive and schedule changes each
     * have a dedicated route).
     */
    public function update(User $user, Bill $bill): bool
    {
        return $this->owner($user, $bill->household_id) !== null;
    }

    /**
     * Determine whether the user can delete the bill.
     *
     * Owner-only. Deleting an unpaid period bill is the permanent skip
     * of that recurrence period; paid bills are refused by the service.
     */
    public function delete(User $user, Bill $bill): bool
    {
        return $this->owner($user, $bill->household_id) !== null;
    }

    /**
     * Determine whether the user can archive the bill.
     *
     * Owner-only, following the wallet deactivate precedent: a dedicated
     * one-way route. Only paid bills may archive (service rule).
     */
    public function archive(User $user, Bill $bill): bool
    {
        return $this->owner($user, $bill->household_id) !== null;
    }

    /**
     * Determine whether the user can pay the bill.
     *
     * Owner-only: a payment posts shared household money from a wallet,
     * exactly like every other finance write. The lifecycle rules
     * (archived, already paid) are re-checked by the service inside the
     * bill row lock; members never reach them — the Gate denial maps to
     * a 404 mask before any 422 could leak the bill's existence.
     */
    public function pay(User $user, Bill $bill): bool
    {
        return $this->owner($user, $bill->household_id) !== null;
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
