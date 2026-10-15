<?php

namespace App\Repositories\Contracts;

use App\Models\Budget;
use App\Models\Household;
use Illuminate\Database\Eloquent\Collection;

interface BudgetRepositoryInterface
{
    /**
     * @param  array{year?: int, month?: int}  $filters
     * @return Collection<int, Budget>
     */
    public function listForHousehold(Household $household, array $filters): Collection;

    public function findByIdForHousehold(Household $household, string $budgetId): ?Budget;

    public function existsForPeriod(Household $household, string $categoryId, int $year, int $month, ?string $exceptId = null): bool;

    public function create(array $data): Budget;

    public function update(Budget $budget, array $data): Budget;

    public function delete(Budget $budget): void;
}
