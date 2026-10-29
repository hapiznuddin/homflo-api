<?php

namespace App\Services\Finance;

use App\Exceptions\BillPaymentImmutableException;
use App\Exceptions\CategoryNotFoundException;
use App\Exceptions\CategoryTypeMismatchException;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\InvalidTransferException;
use App\Exceptions\ListExpenseLockedException;
use App\Exceptions\WalletNotFoundException;
use App\Models\Bill;
use App\Models\Category;
use App\Models\Household;
use App\Models\ListItem;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use App\Repositories\Contracts\WalletRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class TransactionService
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactions,
        private readonly CategoryRepositoryInterface $categories,
        private readonly WalletRepositoryInterface $wallets,
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
     * Every posting locks its wallets and validates the resulting
     * balances inside one database transaction, so concurrent outflows
     * cannot both pass against the same funds.
     *
     * @param  array{type: string, amount: mixed, occurred_on: string, category_id?: ?string, description?: ?string, wallet?: ?string, wallet_id?: ?string, destination_wallet_id?: ?string}  $data
     *
     * @throws CategoryNotFoundException
     * @throws CategoryTypeMismatchException
     * @throws WalletNotFoundException
     * @throws InvalidTransferException
     * @throws InsufficientFundsException
     */
    public function record(Household $household, array $data): Transaction
    {
        $type = $data['type'];
        $category = $this->resolveCategoryForType($household, $data['category_id'] ?? null, $type);
        $amount = $this->normalizeAmount($data['amount']);
        $posting = $this->postingOf($type, $amount, $data['wallet_id'] ?? null, $data['destination_wallet_id'] ?? null);

        return DB::transaction(function () use ($household, $data, $type, $category, $amount, $posting) {
            $locked = $this->lockPostingWallets($household, $posting['wallet_ids']);
            $balances = $this->wallets->balancesFor($household, $locked);

            $this->assertBalancesStayNonNegative($balances, $posting['deltas']);

            return $this->transactions->create([
                'household_id' => $household->id,
                'category_id' => $category?->id,
                'wallet_id' => $posting['wallet_id'],
                'destination_wallet_id' => $posting['destination_wallet_id'],
                'type' => $type,
                'amount' => $amount,
                'occurred_on' => $data['occurred_on'],
                'description' => $data['description'] ?? null,
                'wallet' => $data['wallet'] ?? null,
            ]);
        });
    }

    /**
     * Update mutable transaction fields. Ownership and source linkage
     * are never writable. Wallet and type changes re-validate every
     * affected wallet atomically: the old effect is reverted and the
     * new effect applied before any balance is allowed below zero.
     *
     * @param  array{type?: string, amount?: mixed, occurred_on?: string, category_id?: ?string, description?: ?string, wallet?: ?string, wallet_id?: ?string, destination_wallet_id?: ?string}  $data
     *
     * @throws BillPaymentImmutableException
     * @throws CategoryNotFoundException
     * @throws CategoryTypeMismatchException
     * @throws WalletNotFoundException
     * @throws InvalidTransferException
     * @throws InsufficientFundsException
     */
    public function change(Household $household, Transaction $transaction, array $data): Transaction
    {
        // Bill payments are immutable (frozen contract): the guard reads
        // the persistent source_type column of the server-resolved row —
        // never anything from the payload — and fails before any lock is
        // taken. Generic transactions are untouched by this check.
        if ($transaction->source_type === Bill::class) {
            throw new BillPaymentImmutableException;
        }

        // List item expenses are immutable too (contract §8.5). The guard
        // reads the persistent source_type column, never the payload.
        if ($transaction->source_type === ListItem::class) {
            throw new ListExpenseLockedException;
        }

        $type = $data['type'] ?? $transaction->type;
        $categoryId = array_key_exists('category_id', $data) ? $data['category_id'] : $transaction->category_id;

        // Type/category pairing is only re-validated when one of them
        // changes. Untouched legacy rows (untyped category, wallet-less
        // posting) keep working exactly as before.
        if (array_key_exists('type', $data) || array_key_exists('category_id', $data)) {
            $category = $this->resolveCategoryForType($household, $categoryId, $type);
        } else {
            $category = $transaction->category;
        }

        $amount = array_key_exists('amount', $data) ? $this->normalizeAmount($data['amount']) : (string) $transaction->amount;

        $walletId = array_key_exists('wallet_id', $data) ? $data['wallet_id'] : $transaction->wallet_id;
        $destinationWalletId = array_key_exists('destination_wallet_id', $data)
            ? $data['destination_wallet_id']
            : $transaction->destination_wallet_id;

        if (array_key_exists('wallet_id', $data) && $data['wallet_id'] === null && $transaction->wallet_id !== null) {
            throw new InvalidTransferException('A posted wallet cannot be detached. Move the transaction instead.');
        }

        $old = $this->postingOf(
            $transaction->type,
            (string) $transaction->amount,
            $transaction->wallet_id,
            $transaction->destination_wallet_id,
        );
        $new = $this->postingOf($type, $amount, $walletId, $destinationWalletId);

        return DB::transaction(function () use ($household, $transaction, $data, $category, $type, $amount, $old, $new) {
            $locked = $this->lockPostingWallets($household, array_merge($old['wallet_ids'], $new['wallet_ids']));
            $balances = $this->wallets->balancesFor($household, $locked);

            $deltas = [];

            foreach (array_unique(array_merge(array_keys($old['deltas']), array_keys($new['deltas']))) as $walletId) {
                $deltas[$walletId] = ($new['deltas'][$walletId] ?? 0) - ($old['deltas'][$walletId] ?? 0);
            }

            $this->assertBalancesStayNonNegative($balances, $deltas);

            $attributes = [
                'category_id' => $category?->id,
                'wallet_id' => $new['wallet_id'],
                'destination_wallet_id' => $new['destination_wallet_id'],
                'type' => $type,
                'amount' => $amount,
            ];

            if (array_key_exists('occurred_on', $data)) {
                $attributes['occurred_on'] = $data['occurred_on'];
            }

            if (array_key_exists('description', $data)) {
                $attributes['description'] = $data['description'];
            }

            if (array_key_exists('wallet', $data)) {
                $attributes['wallet'] = $data['wallet'];
            }

            return $this->transactions->update($transaction, $attributes);
        });
    }

    /**
     * @throws BillPaymentImmutableException
     * @throws InsufficientFundsException
     */
    public function remove(Household $household, Transaction $transaction): void
    {
        // Bill payments are immutable (frozen contract): same fail-fast
        // guard as change(), on the persistent source_type column.
        if ($transaction->source_type === Bill::class) {
            throw new BillPaymentImmutableException;
        }

        if ($transaction->source_type === ListItem::class) {
            throw new ListExpenseLockedException;
        }

        $old = $this->postingOf(
            $transaction->type,
            (string) $transaction->amount,
            $transaction->wallet_id,
            $transaction->destination_wallet_id,
        );

        DB::transaction(function () use ($household, $transaction, $old) {
            $locked = $this->lockPostingWallets($household, $old['wallet_ids']);
            $balances = $this->wallets->balancesFor($household, $locked);

            $deltas = [];

            foreach ($old['deltas'] as $walletId => $cents) {
                $deltas[$walletId] = -$cents;
            }

            $this->assertBalancesStayNonNegative($balances, $deltas);

            $this->transactions->delete($transaction);
        });
    }

    /**
     * Resolve a category strictly inside the household and check it
     * against the transaction type. A missing, foreign, or deleted
     * category surfaces identically as not found, so callers cannot
     * probe other households' categories. Legacy categories without a
     * type stay usable for income and expense so historical data keeps
     * working; transfers never carry a category.
     *
     * @throws CategoryNotFoundException
     * @throws CategoryTypeMismatchException
     */
    private function resolveCategoryForType(Household $household, ?string $categoryId, string $type): ?Category
    {
        if ($type === Transaction::TYPE_TRANSFER) {
            if ($categoryId !== null) {
                throw new CategoryTypeMismatchException('Transfer must not have a category.');
            }

            return null;
        }

        $category = $this->resolveCategory($household, $categoryId);

        if ($category !== null && $category->type !== null && $category->type !== $type) {
            throw new CategoryTypeMismatchException('Category type does not match the transaction type.');
        }

        return $category;
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
     * Validate the wallet side of a posting and express its balance
     * effect per wallet in integer cents.
     *
     * @return array{wallet_id: ?string, destination_wallet_id: ?string, wallet_ids: list<string>, deltas: array<string, int>}
     *
     * @throws WalletNotFoundException
     * @throws InvalidTransferException
     */
    private function postingOf(string $type, string $amount, ?string $walletId, ?string $destinationWalletId): array
    {
        throw_unless(
            in_array($type, [Transaction::TYPE_INCOME, Transaction::TYPE_EXPENSE, Transaction::TYPE_TRANSFER], true),
            InvalidTransferException::class,
            'Unknown transaction type.'
        );

        $cents = LedgerMath::toCents($amount);

        if ($type === Transaction::TYPE_TRANSFER) {
            if ($walletId === null || $destinationWalletId === null) {
                throw new InvalidTransferException('Transfer requires a source and a destination wallet.');
            }

            return [
                'wallet_id' => $walletId,
                'destination_wallet_id' => $destinationWalletId,
                'wallet_ids' => [$walletId, $destinationWalletId],
                'deltas' => [$walletId => -$cents, $destinationWalletId => $cents],
            ];
        }

        if ($destinationWalletId !== null) {
            throw new InvalidTransferException('Only transfer rows may carry a destination wallet.');
        }

        if ($walletId === null) {
            // Historical rows posted before wallets existed carry no
            // wallet linkage. They stay readable and editable; they
            // simply contribute no delta to any wallet balance.
            return [
                'wallet_id' => null,
                'destination_wallet_id' => null,
                'wallet_ids' => [],
                'deltas' => [],
            ];
        }

        return [
            'wallet_id' => $walletId,
            'destination_wallet_id' => null,
            'wallet_ids' => [$walletId],
            'deltas' => [$walletId => $type === Transaction::TYPE_INCOME ? $cents : -$cents],
        ];
    }

    /**
     * Resolve every wallet of a posting as active and lock the rows in
     * deterministic order inside the surrounding database transaction.
     *
     * @param  list<string>  $walletIds
     * @return Collection<int, Wallet>
     *
     * @throws WalletNotFoundException
     */
    private function lockPostingWallets(Household $household, array $walletIds): Collection
    {
        $ids = array_values(array_unique(array_filter($walletIds)));

        if ($ids === []) {
            return new Collection;
        }

        $locked = $this->wallets->findLockedByIdsForHousehold($household, $ids);

        if ($locked->count() !== count($ids)) {
            throw new WalletNotFoundException;
        }

        foreach ($locked as $wallet) {
            if (! $wallet->is_active) {
                throw new WalletNotFoundException('Wallet is not active.');
            }
        }

        return $locked;
    }

    /**
     * @param  array<string, string>  $balances  wallet_id => exact 2dp balance
     * @param  array<string, int>  $deltas  wallet_id => signed cents
     *
     * @throws InsufficientFundsException
     */
    private function assertBalancesStayNonNegative(array $balances, array $deltas): void
    {
        foreach ($deltas as $walletId => $cents) {
            if ($cents === 0) {
                continue;
            }

            $projected = LedgerMath::fromCents(LedgerMath::toCents($balances[$walletId] ?? '0.00') + $cents);

            if (LedgerMath::isNegative($projected)) {
                throw new InsufficientFundsException;
            }
        }
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
