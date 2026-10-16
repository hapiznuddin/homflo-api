<?php

namespace App\Repositories;

use App\Models\Category;
use App\Models\Household;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class CategoryRepository implements CategoryRepositoryInterface
{
    public function findByIdForHousehold(Household $household, string $categoryId): ?Category
    {
        return Category::query()
            ->where('household_id', $household->id)
            ->where('id', $categoryId)
            ->first();
    }

    /**
     * @return Collection<int, Category>
     */
    public function listForHousehold(Household $household): Collection
    {
        return Category::query()
            ->where('household_id', $household->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function existsByNameForHousehold(Household $household, string $name, ?string $exceptId = null): bool
    {
        $query = Category::query()
            ->where('household_id', $household->id)
            ->where('name', $name);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->exists();
    }

    public function create(array $data): Category
    {
        return Category::query()->create($data);
    }

    public function update(Category $category, array $data): Category
    {
        $category->update($data);

        return $category->refresh();
    }

    public function delete(Category $category): void
    {
        $category->delete();
    }

    public function hasHistory(Category $category): bool
    {
        return $category->transactions()->exists()
            || $category->budgets()->exists()
            || $category->bills()->exists();
    }
}
