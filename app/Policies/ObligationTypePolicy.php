<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\ObligationType;
use App\Models\User;

class ObligationTypePolicy
{
    /**
     * Any household member may read the system catalogue plus that
     * household's custom types.
     */
    public function viewAny(User $user, Household $household): bool
    {
        return $this->membership($user, $household->id) !== null;
    }

    /**
     * Owner-only: custom types are shared household configuration.
     */
    public function create(User $user, Household $household): bool
    {
        return $this->owner($user, $household->id) !== null;
    }

    /**
     * Built-in types have no household and are immutable. The controller
     * rejects them with a domain error before this check; this remains false
     * as a defensive backstop.
     */
    public function update(User $user, ObligationType $type): bool
    {
        return ! $type->is_system
            && $type->household_id !== null
            && $this->owner($user, $type->household_id) !== null;
    }

    /**
     * Owner-only, custom-only. The service and foreign key protect types
     * that are still referenced by obligations.
     */
    public function delete(User $user, ObligationType $type): bool
    {
        return ! $type->is_system
            && $type->household_id !== null
            && $this->owner($user, $type->household_id) !== null;
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

    private function owner(User $user, Household|string $household): ?HouseholdMember
    {
        $membership = $this->membership($user, $household);

        if ($membership === null || $membership->role !== 'owner') {
            return null;
        }

        return $membership;
    }
}
