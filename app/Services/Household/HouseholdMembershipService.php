<?php

namespace App\Services\Household;

use App\Exceptions\HouseholdLastOwnerException;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Repositories\Contracts\HouseholdMemberRepositoryInterface;
use Illuminate\Support\Facades\DB;

class HouseholdMembershipService
{
    public function __construct(
        private readonly HouseholdMemberRepositoryInterface $householdMembers,
    ) {}

    /**
     * Change a member's role while protecting the last-owner invariant.
     *
     * @throws HouseholdLastOwnerException
     */
    public function changeRole(Household $household, HouseholdMember $membership, string $role): HouseholdMember
    {
        return DB::transaction(function () use ($household, $membership, $role) {
            if ($membership->role === 'owner'
                && $role !== 'owner'
                && $this->householdMembers->countOwnersForHousehold($household) <= 1
            ) {
                throw new HouseholdLastOwnerException;
            }

            return $this->householdMembers->updateRole($membership, $role);
        });
    }

    /**
     * Remove a member while protecting the last-owner invariant.
     *
     * @throws HouseholdLastOwnerException
     */
    public function removeMember(Household $household, HouseholdMember $membership): void
    {
        DB::transaction(function () use ($household, $membership) {
            if ($membership->role === 'owner'
                && $this->householdMembers->countOwnersForHousehold($household) <= 1
            ) {
                throw new HouseholdLastOwnerException;
            }

            $this->householdMembers->delete($membership);
        });
    }
}
