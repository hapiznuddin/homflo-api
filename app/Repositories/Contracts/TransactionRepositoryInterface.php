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

    public function create(array $data): Transaction;

    public function update(Transaction $transaction, array $data): Transaction;

    public function delete(Transaction $transaction): void;
}
