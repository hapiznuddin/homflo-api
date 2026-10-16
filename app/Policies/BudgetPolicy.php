<?php

namespace App\Policies;

use App\Models\Budget;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;

class BudgetPolicy
{
    /**
     * Determine whether the user can list budgets.
     *
     * Any household member (owner or member) may read the shared targets.
     */
    public function viewAny(User $user, Household $household): bool
    {
        return $this->membership($user, $household->id) !== null;
    }

    /**
     * Determine whether the user can view the budget.
     *
     * Any member of the budget's household may view.
     */
    public function view(User $user, Budget $budget): bool
    {
        return $this->membership($user, $budget->household_id) !== null;
    }

    /**
     * Determine whether the user can create a budget.
     *
     * Owner-only: targets constrain the shared household plan.
     */
    public function create(User $user, Household $household): bool
    {
        return $this->owner($user, $household->id) !== null;
    }

    /**
     * Determine whether the user can update the budget.
     *
     * Owner-only.
     */
    public function update(User $user, Budget $budget): bool
    {
        return $this->owner($user, $budget->household_id) !== null;
    }

    /**
     * Determine whether the user can delete the budget.
     *
     * Owner-only. Deleting a budget removes a target only; transaction
     * history is never touched.
     */
    public function delete(User $user, Budget $budget): bool
    {
        return $this->owner($user, $budget->household_id) !== null;
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
