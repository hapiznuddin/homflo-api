<?php

namespace App\Services\Asset;

use App\Models\Asset;
use App\Models\Household;
use App\Repositories\Contracts\AssetRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class AssetService
{
    public function __construct(
        private readonly AssetRepositoryInterface $assets,
    ) {}

    /**
     * @return Collection<int, Asset>
     */
    public function listForHousehold(Household $household): Collection
    {
        return $this->assets->listForHousehold($household);
    }

    /**
     * Create an asset in the household catalogue.
     *
     * Ownership always comes from the server-resolved household; client
     * payload can never set household_id.
     *
     * @param  array{name: string, description?: ?string, location?: ?string, acquired_on?: ?string, acquisition_cost?: mixed}  $data
     */
    public function record(Household $household, array $data): Asset
    {
        return $this->assets->create([
            'household_id' => $household->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'location' => $data['location'] ?? null,
            'acquired_on' => $data['acquired_on'] ?? null,
            'acquisition_cost' => $data['acquisition_cost'] ?? null,
        ]);
    }

    /**
     * Apply a partial update. Only writable asset columns are considered;
     * nullable columns accept an explicit null so a recorded value can be
     * cleared, while omitted keys stay untouched.
     *
     * @param  array{name?: string, description?: ?string, location?: ?string, acquired_on?: ?string, acquisition_cost?: mixed}  $data
     */
    public function change(Asset $asset, array $data): Asset
    {
        $attributes = [];

        if (array_key_exists('name', $data)) {
            $attributes['name'] = $data['name'];
        }

        foreach (['description', 'location', 'acquired_on', 'acquisition_cost'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if ($attributes === []) {
            return $asset;
        }

        return $this->assets->update($asset, $attributes);
    }

    /**
     * Delete the asset row. Related maintenance rows are detached by the
     * database (`asset_id` is ON DELETE SET NULL); they are never removed
     * from here.
     */
    public function remove(Asset $asset): void
    {
        $this->assets->delete($asset);
    }
}
