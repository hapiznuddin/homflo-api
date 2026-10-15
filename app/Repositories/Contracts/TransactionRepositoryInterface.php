<?php

namespace App\Repositories\Contracts;

use App\Models\Household;
use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface TransactionRepositoryInterface
{
    /**
     * @param  array{date_from?: string, date_to?: string, category_id?: string, type?: string}  $filters
     * @return LengthAwarePaginator<int, Transaction>
     */
    public function paginateForHousehold(
        Household $household,
        array $filters,
        string $sortBy,
        string $sortDir,
        int $perPage,
    ): LengthAwarePaginator;

    public function findByIdForHousehold(Household $household, string $transactionId): ?Transaction;

    /**
     * Sum expense amounts grouped by category inside an inclusive date
     * range, using one database aggregation.
     *
     * @return array<string, string> category_id => exact 2dp decimal string
     */
    public function sumExpenseByCategory(Household $household, string $from, string $to): array;

    /**
     * Sum all expense amounts inside an inclusive date range, using one
     * database aggregation. Uncategorized rows are included.
     */
    public function sumExpenseTotal(Household $household, string $from, string $to): string;

    public function create(array $data): Transaction;

    public function update(Transaction $transaction, array $data): Transaction;

    public function delete(Transaction $transaction): void;
}
