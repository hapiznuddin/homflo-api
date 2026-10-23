<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;

class AssetPolicy
{
    /**
     * Determine whether the user can list assets.
     *
     * Any household member (owner or member) may read the shared asset
     * catalogue, like the other household reads.
     */
    public function viewAny(User $user, Household $household): bool
    {
        return $this->membership($user, $household->id) !== null;
    }

    /**
     * Determine whether the user can view the asset.
     *
     * Any member of the asset's household may view.
     */
    public function view(User $user, Asset $asset): bool
    {
        return $this->membership($user, $asset->household_id) !== null;
    }

    /**
     * Determine whether the user can create an asset.
     *
     * Owner-only: assets are shared household records.
     */
    public function create(User $user, Household $household): bool
    {
        return $this->owner($user, $household->id) !== null;
    }

    /**
     * Determine whether the user can update the asset.
     *
     * Owner-only, like the other shared household mutations.
     */
    public function update(User $user, Asset $asset): bool
    {
        return $this->owner($user, $asset->household_id) !== null;
    }

    /**
     * Determine whether the user can delete the asset.
     *
     * Owner-only. Related maintenance rows are detached by the database
     * (ON DELETE SET NULL), never cascaded from the application.
     */
    public function delete(User $user, Asset $asset): bool
    {
        return $this->owner($user, $asset->household_id) !== null;
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
