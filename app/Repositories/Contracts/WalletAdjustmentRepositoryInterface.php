<?php

namespace App\Repositories\Contracts;

use App\Models\Wallet;
use App\Models\WalletAdjustment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface WalletAdjustmentRepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, WalletAdjustment>
     */
    public function paginateForWallet(Wallet $wallet, int $perPage): LengthAwarePaginator;

    public function create(array $data): WalletAdjustment;

    public function sumForWallet(Wallet $wallet): string;
}
