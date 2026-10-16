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
            ->with(['category', 'sourceWallet', 'destinationWallet'])
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
            ->with(['category', 'sourceWallet', 'destinationWallet'])
            ->where('household_id', $household->id)
            ->where('id', $transactionId)
            ->first();
    }

    /**
     * @return array<string, string> category_id => exact 2dp decimal string
     */
    public function sumExpenseByCategory(Household $household, string $from, string $to): array
    {
        $rows = Transaction::query()
            ->selectRaw('category_id, SUM(amount) as total')
            ->where('household_id', $household->id)
            ->where('type', Transaction::TYPE_EXPENSE)
            ->whereNotNull('category_id')
            ->whereDate('occurred_on', '>=', $from)
            ->whereDate('occurred_on', '<=', $to)
            ->groupBy('category_id')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[$row->category_id] = $this->normalizeDecimal($row->total);
        }

        return $totals;
    }

    public function sumExpenseTotal(Household $household, string $from, string $to): string
    {
        $total = Transaction::query()
            ->where('household_id', $household->id)
            ->where('type', Transaction::TYPE_EXPENSE)
            ->whereDate('occurred_on', '>=', $from)
            ->whereDate('occurred_on', '<=', $to)
            ->sum('amount');

        return $this->normalizeDecimal($total);
    }

    /**
     * Normalize a database SUM result (string on pgsql, float|int on
     * sqlite, null when no rows match) to an exact 2dp decimal string.
     * Float64 holds every multiple of 0.01 below 9e13 exactly, so this
     * is lossless inside the validated column max (9999999999999.99).
     */
    private function normalizeDecimal(mixed $total): string
    {
        if ($total === null || $total === '') {
            return '0.00';
        }

        return number_format((float) $total, 2, '.', '');
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
