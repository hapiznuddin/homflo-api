<?php

namespace App\Repositories\Contracts;

use App\Models\Household;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Collection;

interface WalletRepositoryInterface
{
    public function findByIdForHousehold(Household $household, string $walletId): ?Wallet;

    /**
     * Load wallets with row-level locks in deterministic id order, so
     * concurrent transfers lock the same wallets in the same sequence
     * and avoid deadlocks.
     *
     * @param  list<string>  $walletIds
     * @return Collection<int, Wallet>
     */
    public function findLockedByIdsForHousehold(Household $household, array $walletIds): Collection;

    /**
     * @return Collection<int, Wallet>
     */
    public function listForHousehold(Household $household): Collection;

    public function existsByNameForHousehold(Household $household, string $name, ?string $exceptId = null): bool;

    public function create(array $data): Wallet;

    public function update(Wallet $wallet, array $data): Wallet;

    public function delete(Wallet $wallet): void;

    /**
     * Exact 2dp balance: opening + income - expense + transfers in -
     * transfers out + adjustments. Never stored, always derived.
     */
    public function balanceFor(Wallet $wallet): string;

    /**
     * Balances for many wallets without N+1: two grouped aggregations
     * plus openings combined in exact integer cents.
     *
     * @param  Collection<int, Wallet>  $wallets
     * @return array<string, string> wallet_id => exact 2dp balance
     */
    public function balancesFor(Household $household, Collection $wallets): array;

    /**
     * Whether any ledger row or adjustment references the wallet.
     * Wallets with history can only be deactivated, never deleted.
     */
    public function hasHistory(Wallet $wallet): bool;
}
