<?php

namespace App\Services\Finance;

use App\Exceptions\CategoryNotFoundException;
use App\Models\Category;
use App\Models\Household;
use App\Models\Transaction;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TransactionService
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactions,
        private readonly CategoryRepositoryInterface $categories,
    ) {}

    /**
     * @param  array{date_from?: string, date_to?: string, category_id?: string, type?: string}  $filters
     * @return LengthAwarePaginator<int, Transaction>
     */
    public function listForHousehold(
        Household $household,
        array $filters,
        string $sortBy,
        string $sortDir,
        int $perPage,
    ): LengthAwarePaginator {
        return $this->transactions->paginateForHousehold(
            $household,
            $filters,
            $sortBy,
            $sortDir,
            $perPage,
        );
    }

    /**
     * Record a transaction in the household ledger.
     *
     * Ownership always comes from the server-resolved household; client
     * payload can never set household_id, user_id, or source linkage.
     *
     * @param  array{type: string, amount: mixed, occurred_on: string, category_id?: ?string, description?: ?string, wallet?: ?string}  $data
     *
     * @throws CategoryNotFoundException
     */
    public function record(Household $household, array $data): Transaction
    {
        $category = $this->resolveCategory($household, $data['category_id'] ?? null);

        return $this->transactions->create([
            'household_id' => $household->id,
            'category_id' => $category?->id,
            'type' => $data['type'],
            'amount' => $this->normalizeAmount($data['amount']),
            'occurred_on' => $data['occurred_on'],
            'description' => $data['description'] ?? null,
            'wallet' => $data['wallet'] ?? null,
        ]);
    }

    /**
     * Update mutable transaction fields. Ownership and source linkage
     * are never writable.
     *
     * @param  array{type?: string, amount?: mixed, occurred_on?: string, category_id?: ?string, description?: ?string, wallet?: ?string}  $data
     *
     * @throws CategoryNotFoundException
     */
    public function change(Household $household, Transaction $transaction, array $data): Transaction
    {
        $attributes = [];

        if (array_key_exists('type', $data)) {
            $attributes['type'] = $data['type'];
        }

        if (array_key_exists('amount', $data)) {
            $attributes['amount'] = $this->normalizeAmount($data['amount']);
        }

        if (array_key_exists('occurred_on', $data)) {
            $attributes['occurred_on'] = $data['occurred_on'];
        }

        if (array_key_exists('category_id', $data)) {
            $attributes['category_id'] = $this->resolveCategory($household, $data['category_id'])?->id;
        }

        if (array_key_exists('description', $data)) {
            $attributes['description'] = $data['description'];
        }

        if (array_key_exists('wallet', $data)) {
            $attributes['wallet'] = $data['wallet'];
        }

        return $this->transactions->update($transaction, $attributes);
    }

    public function remove(Transaction $transaction): void
    {
        $this->transactions->delete($transaction);
    }

    /**
     * Resolve a category strictly inside the household. A missing,
     * foreign, or deleted category surfaces identically as not found,
     * so callers cannot probe other households' categories.
     *
     * @throws CategoryNotFoundException
     */
    private function resolveCategory(Household $household, ?string $categoryId): ?Category
    {
        if ($categoryId === null) {
            return null;
        }

        $category = $this->categories->findByIdForHousehold($household, $categoryId);

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
