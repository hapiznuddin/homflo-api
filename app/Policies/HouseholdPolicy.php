<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;

class HouseholdPolicy
{
    /**
     * Determine whether the user can view the household.
     *
     * Any household member (owner or member) may view.
     */
    public function view(User $user, Household $household): bool
    {
        return $this->membership($user, $household) !== null;
    }

    /**
     * Determine whether the user can update the household.
     *
     * Only the owner may update.
     */
    public function update(User $user, Household $household): bool
    {
        return $this->membership($user, $household)?->role === 'owner';
    }

    /**
     * Determine whether the user can delete the household.
     *
     * Only the owner may delete.
     */
    public function delete(User $user, Household $household): bool
    {
        return $this->membership($user, $household)?->role === 'owner';
    }

    /**
     * Determine whether the user can list household members.
     *
     * Any household member (owner or member) may list.
     */
    public function viewMembers(User $user, Household $household): bool
    {
        return $this->membership($user, $household) !== null;
    }

    /**
     * Determine whether the user can manage household members.
     *
     * Only the owner may change roles or remove members.
     */
    public function manageMembers(User $user, Household $household): bool
    {
        return $this->membership($user, $household)?->role === 'owner';
    }

    /**
     * Resolve membership from server-side data only.
     *
     * Never trust user_id, household_id, or role from client input.
     */
    private function membership(User $user, Household $household): ?HouseholdMember
    {
        return HouseholdMember::query()
            ->where('user_id', $user->id)
            ->where('household_id', $household->id)
            ->first();
    }
}
