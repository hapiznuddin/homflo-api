<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Models\Vehicle;

class VehiclePolicy
{
    /**
     * Determine whether the user can list vehicles.
     *
     * Any household member (owner or member) may read the shared vehicle
     * catalogue, like the other household reads.
     */
    public function viewAny(User $user, Household $household): bool
    {
        return $this->membership($user, $household->id) !== null;
    }

    /**
     * Determine whether the user can view the vehicle.
     *
     * Any member of the vehicle's household may view.
     */
    public function view(User $user, Vehicle $vehicle): bool
    {
        return $this->membership($user, $vehicle->household_id) !== null;
    }

    /**
     * Determine whether the user can create a vehicle.
     *
     * Owner-only: vehicles are shared household records.
     */
    public function create(User $user, Household $household): bool
    {
        return $this->owner($user, $household->id) !== null;
    }

    /**
     * Determine whether the user can update the vehicle.
     *
     * Owner-only, like the other shared household mutations.
     */
    public function update(User $user, Vehicle $vehicle): bool
    {
        return $this->owner($user, $vehicle->household_id) !== null;
    }

    /**
     * Determine whether the user can delete the vehicle.
     *
     * Owner-only. The service additionally refuses to delete a vehicle that
     * still has service history, so the log is never removed silently.
     */
    public function delete(User $user, Vehicle $vehicle): bool
    {
        return $this->owner($user, $vehicle->household_id) !== null;
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
