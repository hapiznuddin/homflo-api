<?php

namespace App\Repositories\Contracts;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface HouseholdMemberRepositoryInterface
{
    public function findPrimaryMembershipForUser(User $user): ?HouseholdMember;

    public function create(array $data): HouseholdMember;

    /**
     * @return Collection<int, HouseholdMember>
     */
    public function listForHousehold(Household $household): Collection;

    public function findByIdForHousehold(Household $household, string $memberId): ?HouseholdMember;

    public function findByHouseholdAndUser(Household $household, User $user): ?HouseholdMember;

    public function updateRole(HouseholdMember $membership, string $role): HouseholdMember;

    public function delete(HouseholdMember $membership): void;

    public function countOwnersForHousehold(Household $household): int;
}
