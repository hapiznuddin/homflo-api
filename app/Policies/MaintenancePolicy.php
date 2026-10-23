<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Maintenance;
use App\Models\User;

class MaintenancePolicy
{
    /**
     * Determine whether the user can list maintenance records.
     *
     * Any household member (owner or member) may read the shared
     * maintenance schedule, like the other household reads.
     */
    public function viewAny(User $user, Household $household): bool
    {
        return $this->membership($user, $household->id) !== null;
    }

    /**
     * Determine whether the user can view the maintenance record.
     *
     * Any member of the maintenance's household may view.
     */
    public function view(User $user, Maintenance $maintenance): bool
    {
        return $this->membership($user, $maintenance->household_id) !== null;
    }

    /**
     * Determine whether the user can create a maintenance record.
     *
     * Owner-only: maintenance is a shared household record.
     */
    public function create(User $user, Household $household): bool
    {
        return $this->owner($user, $household->id) !== null;
    }

    /**
     * Determine whether the user can update the maintenance record.
     *
     * Owner-only, like the other shared household mutations.
     */
    public function update(User $user, Maintenance $maintenance): bool
    {
        return $this->owner($user, $maintenance->household_id) !== null;
    }

    /**
     * Determine whether the user can delete the maintenance record.
     *
     * Owner-only.
     */
    public function delete(User $user, Maintenance $maintenance): bool
    {
        return $this->owner($user, $maintenance->household_id) !== null;
    }

    /**
     * Determine whether the user can complete the maintenance record.
     *
     * Owner-only: completing is a shared household lifecycle mutation.
     */
    public function complete(User $user, Maintenance $maintenance): bool
    {
        return $this->owner($user, $maintenance->household_id) !== null;
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
     * Resolve an owner membership from server-side data only. Writes stay
     * owner-only; reads stay available to every member.
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
