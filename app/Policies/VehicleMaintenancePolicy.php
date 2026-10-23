<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;

class VehicleMaintenancePolicy
{
    /**
     * Determine whether the user can list a vehicle's service records.
     *
     * Any household member (owner or member) may read the shared service
     * schedule, like the other household reads.
     */
    public function viewAny(User $user, Household $household): bool
    {
        return $this->membership($user, $household->id) !== null;
    }

    /**
     * Determine whether the user can view the service record.
     *
     * Any member of the owning vehicle's household may view. Service records
     * have no household_id of their own, so membership resolves through the
     * vehicle.
     */
    public function view(User $user, VehicleMaintenance $maintenance): bool
    {
        $householdId = $maintenance->vehicle?->household_id;

        return $householdId !== null && $this->membership($user, $householdId) !== null;
    }

    /**
     * Determine whether the user can create a service record for the vehicle.
     *
     * Owner-only: a service record is a shared household mutation.
     */
    public function create(User $user, Vehicle $vehicle): bool
    {
        return $this->owner($user, $vehicle->household_id) !== null;
    }

    /**
     * Determine whether the user can update the service record.
     *
     * Owner-only.
     */
    public function update(User $user, VehicleMaintenance $maintenance): bool
    {
        return $this->isOwnedBy($user, $maintenance);
    }

    /**
     * Determine whether the user can delete the service record.
     *
     * Owner-only.
     */
    public function delete(User $user, VehicleMaintenance $maintenance): bool
    {
        return $this->isOwnedBy($user, $maintenance);
    }

    /**
     * Determine whether the user can complete the service record.
     *
     * Owner-only: completing is a shared household lifecycle mutation.
     */
    public function complete(User $user, VehicleMaintenance $maintenance): bool
    {
        return $this->isOwnedBy($user, $maintenance);
    }

    private function isOwnedBy(User $user, VehicleMaintenance $maintenance): bool
    {
        $householdId = $maintenance->vehicle?->household_id;

        return $householdId !== null && $this->owner($user, $householdId) !== null;
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
