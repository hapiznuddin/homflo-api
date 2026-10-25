<?php

namespace App\Services\Asset;

use App\Exceptions\PropertyArchivedException;
use App\Exceptions\PropertyNotFoundException;
use App\Models\Asset;
use App\Models\Household;
use App\Models\Property;
use App\Repositories\Contracts\AssetRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

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
     * @param  array{name: string, property_id?: ?string, description?: ?string, location?: ?string, acquired_on?: ?string, acquisition_cost?: mixed}  $data
     */
    public function record(Household $household, array $data): Asset
    {
        return DB::transaction(function () use ($household, $data): Asset {
            $propertyId = null;

            if (array_key_exists('property_id', $data) && $data['property_id'] !== null) {
                $this->assertPropertyAssignable($household->id, $data['property_id']);
                $propertyId = $data['property_id'];
            }

            return $this->assets->create([
                'household_id' => $household->id,
                'property_id' => $propertyId,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'location' => $data['location'] ?? null,
                'acquired_on' => $data['acquired_on'] ?? null,
                'acquisition_cost' => $data['acquisition_cost'] ?? null,
            ]);
        });
    }

    /**
     * Apply a partial update. Only writable asset columns are considered;
     * nullable columns accept an explicit null so a recorded value can be
     * cleared, while omitted keys stay untouched.
     *
     * An omitted property_id keeps the existing relation. An explicit null
     * detaches it. A different id must resolve to an active Property in the
     * same household; the current id is accepted unchanged even if that
     * Property has since been archived.
     *
     * @param  array{name?: string, property_id?: ?string, description?: ?string, location?: ?string, acquired_on?: ?string, acquisition_cost?: mixed}  $data
     */
    public function change(Asset $asset, array $data): Asset
    {
        return DB::transaction(function () use ($asset, $data): Asset {
            $attributes = [];

            if (array_key_exists('name', $data)) {
                $attributes['name'] = $data['name'];
            }

            foreach (['description', 'location', 'acquired_on', 'acquisition_cost'] as $field) {
                if (array_key_exists($field, $data)) {
                    $attributes[$field] = $data[$field];
                }
            }

            if (array_key_exists('property_id', $data)) {
                $targetPropertyId = $data['property_id'];

                if ($targetPropertyId === null) {
                    if ($asset->property_id !== null) {
                        $attributes['property_id'] = null;
                    }
                } elseif ($targetPropertyId !== $asset->property_id) {
                    $this->assertPropertyAssignable($asset->household_id, $targetPropertyId);
                    $attributes['property_id'] = $targetPropertyId;
                }
            }

            if ($attributes === []) {
                return $asset;
            }

            return $this->assets->update($asset, $attributes);
        });
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

    /**
     * Resolve the target within the Asset household so a foreign Property is
     * indistinguishable from a missing one. The row lock serializes this
     * check against archive/restore writes in databases that support it.
     */
    private function assertPropertyAssignable(string $householdId, string $propertyId): void
    {
        $property = Property::query()
            ->where('household_id', $householdId)
            ->where('id', $propertyId)
            ->lockForUpdate()
            ->first();

        if ($property === null) {
            throw new PropertyNotFoundException;
        }

        if ($property->archived_at !== null) {
            throw new PropertyArchivedException;
        }
    }
}
