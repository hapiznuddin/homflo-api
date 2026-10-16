<?php

namespace App\Repositories;

use App\Models\Household;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Services\Finance\LedgerMath;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WalletRepository implements WalletRepositoryInterface
{
    public function findByIdForHousehold(Household $household, string $walletId): ?Wallet
    {
        return Wallet::query()
            ->where('household_id', $household->id)
            ->where('id', $walletId)
            ->first();
    }

    /**
     * @param  list<string>  $walletIds
     * @return Collection<int, Wallet>
     */
    public function findLockedByIdsForHousehold(Household $household, array $walletIds): Collection
    {
        $ids = array_values(array_unique($walletIds));
        sort($ids);

        return Wallet::query()
            ->where('household_id', $household->id)
            ->whereIn('id', $ids)
            ->lockForUpdate()
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Wallet>
     */
    public function listForHousehold(Household $household): Collection
    {
        return Wallet::query()
            ->where('household_id', $household->id)
            ->orderBy('name')
            ->get();
    }

    public function existsByNameForHousehold(Household $household, string $name, ?string $exceptId = null): bool
    {
        return Wallet::query()
            ->where('household_id', $household->id)
            ->where('name', $name)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();
    }

    public function create(array $data): Wallet
    {
        return Wallet::query()->create($data);
    }

    public function update(Wallet $wallet, array $data): Wallet
    {
        $wallet->update($data);

        return $wallet->refresh();
    }

    public function delete(Wallet $wallet): void
    {
        $wallet->delete();
    }

    public function balanceFor(Wallet $wallet): string
    {
        return $this->balancesFor($wallet->household, new Collection([$wallet]))[$wallet->id] ?? '0.00';
    }

    /**
     * @param  Collection<int, Wallet>  $wallets
     * @return array<string, string>
     */
    public function balancesFor(Household $household, Collection $wallets): array
    {
        $ids = $wallets->map(fn (Wallet $wallet) => $wallet->id)->all();

        if ($ids === []) {
            return [];
        }

        $posted = DB::table('transactions')
            ->select('wallet_id')
            ->selectRaw("SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS income_sum")
            ->selectRaw("SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS expense_sum")
            ->selectRaw("SUM(CASE WHEN type = 'transfer' THEN amount ELSE 0 END) AS transfer_out_sum")
            ->where('household_id', $household->id)
            ->whereIn('wallet_id', $ids)
            ->groupBy('wallet_id')
            ->get()
            ->keyBy('wallet_id');

        $received = DB::table('transactions')
            ->select('destination_wallet_id AS wallet_id')
            ->selectRaw('SUM(amount) AS transfer_in_sum')
            ->where('household_id', $household->id)
            ->where('type', Transaction::TYPE_TRANSFER)
            ->whereIn('destination_wallet_id', $ids)
            ->groupBy('destination_wallet_id')
            ->get()
            ->keyBy('wallet_id');

        $adjusted = DB::table('wallet_adjustments')
            ->select('wallet_id')
            ->selectRaw('SUM(amount) AS adjustments_sum')
            ->where('household_id', $household->id)
            ->whereIn('wallet_id', $ids)
            ->groupBy('wallet_id')
            ->get()
            ->keyBy('wallet_id');

        $balances = [];

        foreach ($wallets as $wallet) {
            $cents = LedgerMath::toCents($this->normalizeDecimal($wallet->opening_balance));

            $row = $posted[$wallet->id] ?? null;

            if ($row !== null) {
                $cents += LedgerMath::toCents($this->normalizeDecimal($row->income_sum));
                $cents -= LedgerMath::toCents($this->normalizeDecimal($row->expense_sum));
                $cents -= LedgerMath::toCents($this->normalizeDecimal($row->transfer_out_sum));
            }

            $incoming = $received[$wallet->id] ?? null;

            if ($incoming !== null) {
                $cents += LedgerMath::toCents($this->normalizeDecimal($incoming->transfer_in_sum));
            }

            $correction = $adjusted[$wallet->id] ?? null;

            if ($correction !== null) {
                $cents += LedgerMath::toCents($this->normalizeDecimal($correction->adjustments_sum));
            }

            $balances[$wallet->id] = LedgerMath::fromCents($cents);
        }

        return $balances;
    }

    public function hasHistory(Wallet $wallet): bool
    {
        return Transaction::query()
            ->where(function ($query) use ($wallet) {
                $query->where('wallet_id', $wallet->id)
                    ->orWhere('destination_wallet_id', $wallet->id);
            })
            ->exists()
            || $wallet->adjustments()->exists();
    }

    /**
     * Normalize a database SUM result (string on pgsql, float|int on
     * sqlite, null when no rows match) to an exact 2dp decimal string
     * before it enters integer-cents math.
     */
    private function normalizeDecimal(mixed $total): string
    {
        if ($total === null || $total === '') {
            return '0.00';
        }

        return number_format((float) $total, 2, '.', '');
    }
}
