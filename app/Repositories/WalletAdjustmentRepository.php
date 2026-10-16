<?php

namespace App\Repositories;

use App\Models\Wallet;
use App\Models\WalletAdjustment;
use App\Repositories\Contracts\WalletAdjustmentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class WalletAdjustmentRepository implements WalletAdjustmentRepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, WalletAdjustment>
     */
    public function paginateForWallet(Wallet $wallet, int $perPage): LengthAwarePaginator
    {
        return WalletAdjustment::query()
            ->with('creator')
            ->where('wallet_id', $wallet->id)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    }

    public function create(array $data): WalletAdjustment
    {
        return WalletAdjustment::query()->create($data);
    }

    public function sumForWallet(Wallet $wallet): string
    {
        $total = WalletAdjustment::query()
            ->where('wallet_id', $wallet->id)
            ->sum('amount');

        if ($total === null || $total === '') {
            return '0.00';
        }

        return number_format((float) $total, 2, '.', '');
    }
}
