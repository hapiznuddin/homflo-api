<?php

namespace App\Repositories\Contracts;

use App\Models\Asset;
use App\Models\Household;
use Illuminate\Database\Eloquent\Collection;

interface AssetRepositoryInterface
{
    public function findByIdForHousehold(Household $household, string $assetId): ?Asset;

    /**
     * @return Collection<int, Asset>
     */
    public function listForHousehold(Household $household): Collection;

    public function create(array $data): Asset;

    public function update(Asset $asset, array $data): Asset;

    public function delete(Asset $asset): void;
}
