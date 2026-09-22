<?php

namespace App\Repositories;

use App\Models\HouseholdMember;
use App\Models\User;
use App\Repositories\Contracts\HouseholdMemberRepositoryInterface;

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
}
