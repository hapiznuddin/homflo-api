<?php

namespace App\Services\Finance;

use App\Exceptions\InsufficientFundsException;
use App\Exceptions\WalletAlreadyExistsException;
use App\Exceptions\WalletInUseException;
use App\Exceptions\WalletNotFoundException;
use App\Models\Household;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletAdjustment;
use App\Repositories\Contracts\WalletAdjustmentRepositoryInterface;
use App\Repositories\Contracts\WalletRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WalletService
{
    public function __construct(
        private readonly WalletRepositoryInterface $wallets,
        private readonly WalletAdjustmentRepositoryInterface $adjustments,
    ) {}

    /**
     * @return array{wallets: Collection<int, Wallet>, balances: array<string, string>}
     */
    public function listForHousehold(Household $household): array
    {
        $wallets = $this->wallets->listForHousehold($household);

        return [
            'wallets' => $wallets,
            'balances' => $this->wallets->balancesFor($household, $wallets),
        ];
    }

    /**
     * @param  array{name: string, type: string, opening_balance: mixed}  $data
     *
     * @throws WalletAlreadyExistsException
     */
    public function record(Household $household, array $data): Wallet
    {
        if ($this->wallets->existsByNameForHousehold($household, $data['name'])) {
            throw new WalletAlreadyExistsException;
        }

        return $this->wallets->create([
            'household_id' => $household->id,
            'name' => $data['name'],
            'type' => $data['type'],
            'opening_balance' => $this->normalizeAmount($data['opening_balance'] ?? 0),
            'is_active' => true,
        ]);
    }

    /**
     * @param  array{name?: string, type?: string}  $data
     *
     * @throws WalletAlreadyExistsException
     */
    public function change(Household $household, Wallet $wallet, array $data): Wallet
    {
        $attributes = [];

        if (array_key_exists('name', $data)) {
            if ($this->wallets->existsByNameForHousehold($household, $data['name'], $wallet->id)) {
                throw new WalletAlreadyExistsException;
            }

            $attributes['name'] = $data['name'];
        }

        if (array_key_exists('type', $data)) {
            $attributes['type'] = $data['type'];
        }

        return $this->wallets->update($wallet, $attributes);
    }

    /**
     * Deactivate a wallet. History stays readable; the wallet simply
     * stops being selectable for new postings. Idempotent.
     */
    public function deactivate(Wallet $wallet): Wallet
    {
        if (! $wallet->is_active) {
            return $wallet;
        }

        return $this->wallets->update($wallet, ['is_active' => false]);
    }

    /**
     * Delete a wallet with no financial history whatsoever.
     *
     * @throws WalletInUseException
     */
    public function remove(Wallet $wallet): void
    {
        if ($this->wallets->hasHistory($wallet)) {
            throw new WalletInUseException;
        }

        $this->wallets->delete($wallet);
    }

    /**
     * Resolve a wallet strictly inside the household. A missing or
     * foreign wallet surfaces identically as not found, so callers
     * cannot probe other households' wallets.
     *
     * @throws WalletNotFoundException
     */
    public function resolve(Household $household, string $walletId): Wallet
    {
        $wallet = $this->wallets->findByIdForHousehold($household, $walletId);

        if ($wallet === null) {
            throw new WalletNotFoundException;
        }

        return $wallet;
    }

    /**
     * Resolve a wallet that may receive a new posting: it must exist in
     * the household and be active.
     *
     * @throws WalletNotFoundException
     */
    public function resolveActive(Household $household, string $walletId): Wallet
    {
        $wallet = $this->resolve($household, $walletId);

        if (! $wallet->is_active) {
            throw new WalletNotFoundException('Wallet is not active.');
        }

        return $wallet;
    }

    /**
     * Record an audited balance correction. The amount may be positive
     * or negative but never zero; the resulting balance must stay
     * non-negative. The row is immutable: later corrections are new rows.
     *
     * @param  array{amount: mixed, reason: string}  $data
     *
     * @throws WalletNotFoundException
     * @throws InsufficientFundsException
     */
    public function adjust(Household $household, Wallet $wallet, User $actor, array $data): WalletAdjustment
    {
        if (! $wallet->is_active) {
            throw new WalletNotFoundException('Wallet is not active.');
        }

        $amount = $this->normalizeAmount($data['amount']);

        if (LedgerMath::isZero($amount)) {
            throw new InsufficientFundsException('Adjustment amount must not be zero.');
        }

        return DB::transaction(function () use ($household, $wallet, $actor, $amount, $data) {
            $locked = $this->wallets->findLockedByIdsForHousehold($household, [$wallet->id]);

            if ($locked->count() !== 1) {
                throw new WalletNotFoundException;
            }

            $before = $this->wallets->balanceFor($locked->first());
            $after = LedgerMath::add($before, $amount);

            if (LedgerMath::isNegative($after)) {
                throw new InsufficientFundsException;
            }

            return $this->adjustments->create([
                'household_id' => $household->id,
                'wallet_id' => $wallet->id,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'reason' => $data['reason'],
                'created_by' => $actor->id,
            ]);
        });
    }

    /**
     * Normalize validated input to the exact decimal(15,2) storage shape.
     */
    private function normalizeAmount(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
