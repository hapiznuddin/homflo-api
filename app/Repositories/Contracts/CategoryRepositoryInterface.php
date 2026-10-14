<?php

namespace App\Repositories\Contracts;

use App\Models\Category;
use App\Models\Household;
use Illuminate\Database\Eloquent\Collection;

interface CategoryRepositoryInterface
{
    public function findByIdForHousehold(Household $household, string $categoryId): ?Category;

    /**
     * @return Collection<int, Category>
     */
    public function listForHousehold(Household $household): Collection;

    public function existsByNameForHousehold(Household $household, string $name, ?string $exceptId = null): bool;

    public function create(array $data): Category;

    public function update(Category $category, array $data): Category;

    public function delete(Category $category): void;
}
