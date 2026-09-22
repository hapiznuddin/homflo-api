<?php

namespace App\Repositories;

use App\Models\Household;
use App\Repositories\Contracts\HouseholdRepositoryInterface;

class HouseholdRepository implements HouseholdRepositoryInterface
{
    public function create(array $data): Household
    {
        return Household::query()->create($data);
    }
}
