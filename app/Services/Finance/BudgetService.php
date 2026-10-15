<?php

namespace App\Services\Finance;

use App\Exceptions\BudgetAlreadyExistsException;
use App\Exceptions\CategoryNotFoundException;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Household;
use App\Repositories\Contracts\BudgetRepositoryInterface;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class BudgetService
{
    public function __construct(
        private readonly BudgetRepositoryInterface $budgets,
        private readonly CategoryRepositoryInterface $categories,
    ) {}

    /**
     * @param  array{year?: int, month?: int}  $filters
     * @return Collection<int, Budget>
     */
    public function listForHousehold(Household $household, array $filters): Collection
    {
        return $this->budgets->listForHousehold($household, $filters);
    }

    /**
     * Set a spending target for one category in one month.
     *
     * Ownership always comes from the server-resolved household; client
     * payload can never set household_id. Spending itself is never stored
     * here — it is derived from transactions when a confirmed definition
     * exists.
     *
     * @param  array{category_id: string, year: int, month: int, amount: mixed}  $data
     *
     * @throws CategoryNotFoundException
     * @throws BudgetAlreadyExistsException
     */
    public function record(Household $household, array $data): Budget
    {
        $category = $this->resolveCategory($household, $data['category_id']);

        if ($this->budgets->existsForPeriod($household, $category->id, (int) $data['year'], (int) $data['month'])) {
            throw new BudgetAlreadyExistsException;
        }

        return $this->budgets->create([
            'household_id' => $household->id,
            'category_id' => $category->id,
            'year' => (int) $data['year'],
            'month' => (int) $data['month'],
            'amount' => $this->normalizeAmount($data['amount']),
        ]);
    }

    /**
     * Change a budget target. Retargeting to another category or period
     * is allowed as long as it does not collide with an existing target.
     *
     * @param  array{category_id?: string, year?: int, month?: int, amount?: mixed}  $data
     *
     * @throws CategoryNotFoundException
     * @throws BudgetAlreadyExistsException
     */
    public function change(Household $household, Budget $budget, array $data): Budget
    {
        $attributes = [];

        $categoryId = $budget->category_id;
        $year = $budget->year;
        $month = $budget->month;

        if (array_key_exists('category_id', $data)) {
            $categoryId = $this->resolveCategory($household, $data['category_id'])->id;
            $attributes['category_id'] = $categoryId;
        }

        if (array_key_exists('year', $data)) {
            $year = (int) $data['year'];
            $attributes['year'] = $year;
        }

        if (array_key_exists('month', $data)) {
            $month = (int) $data['month'];
            $attributes['month'] = $month;
        }

        if ($this->budgets->existsForPeriod($household, $categoryId, $year, $month, $budget->id)) {
            throw new BudgetAlreadyExistsException;
        }

        if (array_key_exists('amount', $data)) {
            $attributes['amount'] = $this->normalizeAmount($data['amount']);
        }

        return $this->budgets->update($budget, $attributes);
    }

    /**
     * Delete a budget target. Transaction history is never touched:
     * nothing references budgets, so deletion cannot orphan records.
     */
    public function remove(Budget $budget): void
    {
        $this->budgets->delete($budget);
    }

    /**
     * Resolve a category strictly inside the household. A missing,
     * foreign, or deleted category surfaces identically as not found,
     * so callers cannot probe other households' categories.
     *
     * @throws CategoryNotFoundException
     */
    private function resolveCategory(Household $household, ?string $categoryId): Category
    {
        $category = $categoryId === null
            ? null
            : $this->categories->findByIdForHousehold($household, $categoryId);

        if ($category === null) {
            throw new CategoryNotFoundException;
        }

        return $category;
    }

    /**
     * Normalize validated input to the exact decimal(15,2) storage shape.
     *
     * Float64 represents every multiple of 0.01 below 9e13 exactly, so
     * this is lossless inside the validated max (9999999999999.99).
     */
    private function normalizeAmount(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
