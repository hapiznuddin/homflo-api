<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;

class CategoryPolicy
{
    /**
     * Determine whether the user can list categories.
     *
     * Any household member (owner or member) may read the shared catalogue.
     */
    public function viewAny(User $user, Household $household): bool
    {
        return $this->membership($user, $household->id) !== null;
    }

    /**
     * Determine whether the user can view the category.
     *
     * Any member of the category's household may view.
     */
    public function view(User $user, Category $category): bool
    {
        return $this->membership($user, $category->household_id) !== null;
    }

    /**
     * Determine whether the user can create a category.
     *
     * Owner-only: categories classify the shared ledger.
     */
    public function create(User $user, Household $household): bool
    {
        return $this->owner($user, $household->id) !== null;
    }

    /**
     * Determine whether the user can update the category.
     *
     * Owner-only: re-typing history is blocked at the service layer.
     */
    public function update(User $user, Category $category): bool
    {
        return $this->owner($user, $category->household_id) !== null;
    }

    /**
     * Determine whether the user can delete the category.
     *
     * Owner-only. Usage guards (budgets RESTRICT) are enforced by the
     * database, not by roles.
     */
    public function delete(User $user, Category $category): bool
    {
        return $this->owner($user, $category->household_id) !== null;
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
