<?php

namespace App\Policies;

use App\Models\Property;
use App\Models\Household;
use App\Models\User;
use App\Models\HouseholdMember;

class PropertyPolicy
{
    /** Determine whether the user can list properties. */
    public function viewAny(User $user, Household $household): bool
    {
        return $this->membership($user, $household->id) !== null;
    }

    /** Determine whether the user can view the property. */
    public function view(User $user, Property $property): bool
    {
        return $this->membership($user, $property->household_id) !== null;
    }

    /** Determine whether the user can create a property. */
    public function create(User $user, Household $household): bool
    {
        return $this->owner($user, $household->id) !== null;
    }

    /** Determine whether the user can update the property. */
    public function update(User $user, Property $property): bool
    {
        return $this->owner($user, $property->household_id) !== null;
    }

    /** Determine whether the user can delete the property. */
    public function delete(User $user, Property $property): bool
    {
        return $this->owner($user, $property->household_id) !== null;
    }

    /** Resolve membership from server-side data only. */
    private function membership(User $user, Household|string $household): ?HouseholdMember
    {
        return HouseholdMember::query()
            ->where('user_id', $user->id)
            ->where('household_id', $household instanceof Household ? $household->id : $household)
            ->first();
    }

    /** Resolve an owner membership from server-side data only. */
    private function owner(User $user, Household|string $household): ?HouseholdMember
    {
        $membership = $this->membership($user, $household);

        if ($membership === null || $membership->role !== 'owner') {
            return null;
        }

        return $membership;
    }
}