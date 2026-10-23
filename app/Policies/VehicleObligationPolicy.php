<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleObligation;

class VehicleObligationPolicy
{
    /**
     * Any household member may read a vehicle's obligations.
     */
    public function viewAny(User $user, Household $household): bool
    {
        return $this->membership($user, $household->id) !== null;
    }

    /**
     * Household membership resolves through the obligation's vehicle because
     * vehicle_obligations has no household_id of its own.
     */
    public function view(User $user, VehicleObligation $obligation): bool
    {
        $householdId = $obligation->vehicle?->household_id;

        return $householdId !== null && $this->membership($user, $householdId) !== null;
    }

    /**
     * Owner-only shared household mutation.
     */
    public function create(User $user, Vehicle $vehicle): bool
    {
        return $this->owner($user, $vehicle->household_id) !== null;
    }

    public function update(User $user, VehicleObligation $obligation): bool
    {
        return $this->isOwnedBy($user, $obligation);
    }

    public function delete(User $user, VehicleObligation $obligation): bool
    {
        return $this->isOwnedBy($user, $obligation);
    }

    private function isOwnedBy(User $user, VehicleObligation $obligation): bool
    {
        $householdId = $obligation->vehicle?->household_id;

        return $householdId !== null && $this->owner($user, $householdId) !== null;
    }

    /**
     * Resolve membership from server-side data only.
     */
    private function membership(User $user, Household|string $household): ?HouseholdMember
    {
        return HouseholdMember::query()
            ->where('user_id', $user->id)
            ->where('household_id', $household instanceof Household ? $household->id : $household)
            ->first();
    }

    /**
     * Reads remain available to every member; writes stay owner-only.
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
