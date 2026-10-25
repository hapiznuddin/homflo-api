<?php

namespace App\Repositories;

use App\Models\Asset;
use App\Models\Household;
use App\Repositories\Contracts\AssetRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class AssetRepository implements AssetRepositoryInterface
{
    public function findByIdForHousehold(Household $household, string $assetId): ?Asset
    {
        return Asset::query()
            ->with(['property:id,name,archived_at'])
            ->where('household_id', $household->id)
            ->where('id', $assetId)
            ->first();
    }

    /**
     * @return Collection<int, Asset>
     */
    public function listForHousehold(Household $household): Collection
    {
        return Asset::query()
            ->with(['property:id,name,archived_at'])
            ->where('household_id', $household->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function create(array $data): Asset
    {
        return Asset::query()
            ->create($data)
            ->load('property:id,name,archived_at');
    }

    public function update(Asset $asset, array $data): Asset
    {
        $asset->update($data);

        return $asset->refresh()->load('property:id,name,archived_at');
    }

    public function delete(Asset $asset): void
    {
        $asset->delete();
    }
}
