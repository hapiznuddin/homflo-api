<?php

namespace App\Services\Finance;

use App\Exceptions\CategoryAlreadyExistsException;
use App\Exceptions\CategoryInUseException;
use App\Exceptions\CategoryTypeMismatchException;
use App\Models\Category;
use App\Models\Household;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

class CategoryService
{
    public function __construct(
        private readonly CategoryRepositoryInterface $categories,
    ) {}

    /**
     * @return Collection<int, Category>
     */
    public function listForHousehold(Household $household): Collection
    {
        return $this->categories->listForHousehold($household);
    }

    /**
     * Create a category in the household catalogue.
     *
     * Ownership always comes from the server-resolved household; client
     * payload can never set household_id.
     *
     * @param  array{name: string, icon?: ?string, type: string}  $data
     *
     * @throws CategoryAlreadyExistsException
     */
    public function record(Household $household, array $data): Category
    {
        if ($this->categories->existsByNameForHousehold($household, $data['name'])) {
            throw new CategoryAlreadyExistsException;
        }

        return $this->categories->create([
            'household_id' => $household->id,
            'name' => $data['name'],
            'icon' => $data['icon'] ?? null,
            'type' => $data['type'],
        ]);
    }

    /**
     * Rename a category, change its icon, or classify a legacy untyped
     * category. The unique check excludes the category itself so no-op
     * updates stay valid. Re-typing a category that already classifies
     * history is rejected: history must never be reinterpreted by an
     * edit. A legacy category without a type may be classified once, or
     * re-classified while still unused.
     *
     * @param  array{name?: string, icon?: ?string, type?: string}  $data
     *
     * @throws CategoryAlreadyExistsException
     * @throws CategoryTypeMismatchException
     */
    public function change(Household $household, Category $category, array $data): Category
    {
        $attributes = [];

        if (array_key_exists('name', $data)) {
            if ($this->categories->existsByNameForHousehold($household, $data['name'], $category->id)) {
                throw new CategoryAlreadyExistsException;
            }

            $attributes['name'] = $data['name'];
        }

        if (array_key_exists('icon', $data)) {
            $attributes['icon'] = $data['icon'];
        }

        if (array_key_exists('type', $data)) {
            if ($data['type'] !== $category->type
                && $category->type !== null
                && $this->categories->hasHistory($category)) {
                throw new CategoryTypeMismatchException('Category with history cannot change type.');
            }

            $attributes['type'] = $data['type'];
        }

        return $this->categories->update($category, $attributes);
    }

    /**
     * Delete a category. Linked transactions and bills detach via
     * ON DELETE SET NULL; a linked budget blocks deletion at the database
     * level and surfaces as a domain error instead of a 500.
     *
     * @throws CategoryInUseException
     */
    public function remove(Category $category): void
    {
        try {
            $this->categories->delete($category);
        } catch (QueryException $exception) {
            throw new CategoryInUseException;
        }
    }
}
