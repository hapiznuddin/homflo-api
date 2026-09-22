<?php

namespace App\Repositories\Contracts;

use App\Models\HouseholdMember;
use App\Models\User;

interface HouseholdMemberRepositoryInterface
{
    public function findPrimaryMembershipForUser(User $user): ?HouseholdMember;

    public function create(array $data): HouseholdMember;
}
