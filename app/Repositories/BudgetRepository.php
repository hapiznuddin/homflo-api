<?php

namespace App\Repositories;

use App\Models\Budget;
use App\Models\Household;
use App\Repositories\Contracts\BudgetRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class BudgetRepository implements BudgetRepositoryInterface
{
    /**
     * @param  array{year?: int, month?: int}  $filters
     * @return Collection<int, Budget>
     */
    public function listForHousehold(Household $household, array $filters): Collection
    {
        $query = Budget::query()
            ->with('category')
            ->where('household_id', $household->id);

        if (isset($filters['year'])) {
            $query->where('year', $filters['year']);
        }

        if (isset($filters['month'])) {
            $query->where('month', $filters['month']);
        }

        return $query
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderBy('id')
            ->get();
    }

    public function findByIdForHousehold(Household $household, string $budgetId): ?Budget
    {
        return Budget::query()
            ->with('category')
            ->where('household_id', $household->id)
            ->where('id', $budgetId)
            ->first();
    }

    public function existsForPeriod(Household $household, string $categoryId, int $year, int $month, ?string $exceptId = null): bool
    {
        $query = Budget::query()
            ->where('household_id', $household->id)
            ->where('category_id', $categoryId)
            ->where('year', $year)
            ->where('month', $month);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->exists();
    }

    public function create(array $data): Budget
    {
        return Budget::query()->create($data);
    }

    public function update(Budget $budget, array $data): Budget
    {
        $budget->update($data);

        return $budget->refresh();
    }

    public function delete(Budget $budget): void
    {
        $budget->delete();
    }
}
