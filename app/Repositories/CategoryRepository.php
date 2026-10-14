<?php

namespace App\Repositories;

use App\Models\Category;
use App\Models\Household;
use App\Repositories\Contracts\CategoryRepositoryInterface;

class CategoryRepository implements CategoryRepositoryInterface
{
    public function findByIdForHousehold(Household $household, string $categoryId): ?Category
    {
        return Category::query()
            ->where('household_id', $household->id)
            ->where('id', $categoryId)
            ->first();
    }
}
