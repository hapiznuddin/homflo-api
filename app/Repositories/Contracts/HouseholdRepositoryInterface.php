<?php

namespace App\Repositories\Contracts;

use App\Models\Household;

interface HouseholdRepositoryInterface
{
    public function create(array $data): Household;
}
