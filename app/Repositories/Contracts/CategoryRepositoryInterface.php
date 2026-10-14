<?php

namespace App\Repositories\Contracts;

use App\Models\Category;
use App\Models\Household;

interface CategoryRepositoryInterface
{
    public function findByIdForHousehold(Household $household, string $categoryId): ?Category;
}
