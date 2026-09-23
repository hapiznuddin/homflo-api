<?php

namespace App\Repositories;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Repositories\Contracts\HouseholdMemberRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class HouseholdMemberRepository implements HouseholdMemberRepositoryInterface
{
    public function findPrimaryMembershipForUser(User $user): ?HouseholdMember
    {
        return HouseholdMember::query()
            ->with('household')
            ->where('user_id', $user->id)
            ->first();
    }

    public function create(array $data): HouseholdMember
    {
        return HouseholdMember::query()->create($data);
    }

    /**
     * @return Collection<int, HouseholdMember>
     */
    public function listForHousehold(Household $household): Collection
    {
        return HouseholdMember::query()
            ->with('user')
            ->where('household_id', $household->id)
            ->orderBy('created_at')
            ->get();
    }

    public function findByIdForHousehold(Household $household, string $memberId): ?HouseholdMember
    {
        return HouseholdMember::query()
            ->with('user')
            ->where('household_id', $household->id)
            ->where('id', $memberId)
            ->first();
    }

    public function findByHouseholdAndUser(Household $household, User $user): ?HouseholdMember
    {
        return HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('user_id', $user->id)
            ->first();
    }

    public function updateRole(HouseholdMember $membership, string $role): HouseholdMember
    {
        $membership->update(['role' => $role]);

        return $membership->refresh();
    }

    public function delete(HouseholdMember $membership): void
    {
        $membership->delete();
    }

    public function countOwnersForHousehold(Household $household): int
    {
        return HouseholdMember::query()
            ->where('household_id', $household->id)
            ->where('role', 'owner')
            ->count();
    }
}
