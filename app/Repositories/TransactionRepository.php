<?php

namespace App\Repositories;

use App\Models\Household;
use App\Models\Transaction;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TransactionRepository implements TransactionRepositoryInterface
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
    ): LengthAwarePaginator {
        $column = match ($sortBy) {
            'amount' => 'amount',
            'created_at' => 'created_at',
            default => 'occurred_on',
        };

        $direction = $sortDir === 'asc' ? 'asc' : 'desc';

        $query = Transaction::query()
            ->with('category')
            ->where('household_id', $household->id);

        if (isset($filters['date_from'])) {
            $query->whereDate('occurred_on', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->whereDate('occurred_on', '<=', $filters['date_to']);
        }

        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        return $query
            ->orderBy($column, $direction)
            ->orderBy('id', $direction)
            ->paginate($perPage);
    }

    public function findByIdForHousehold(Household $household, string $transactionId): ?Transaction
    {
        return Transaction::query()
            ->with('category')
            ->where('household_id', $household->id)
            ->where('id', $transactionId)
            ->first();
    }

    public function create(array $data): Transaction
    {
        return Transaction::query()->create($data);
    }

    public function update(Transaction $transaction, array $data): Transaction
    {
        $transaction->update($data);

        return $transaction->refresh();
    }

    public function delete(Transaction $transaction): void
    {
        $transaction->delete();
    }
}
