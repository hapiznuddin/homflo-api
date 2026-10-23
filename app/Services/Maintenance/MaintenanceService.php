<?php

namespace App\Services\Maintenance;

use App\Exceptions\AssetNotFoundException;
use App\Exceptions\MaintenanceAlreadyCompletedException;
use App\Models\Asset;
use App\Models\Household;
use App\Models\Maintenance;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Repositories\Contracts\MaintenanceRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class MaintenanceService
{
    public function __construct(
        private readonly MaintenanceRepositoryInterface $maintenances,
        private readonly AssetRepositoryInterface $assets,
    ) {}

    /**
     * @return Collection<int, Maintenance>
     */
    public function listForHousehold(Household $household): Collection
    {
        return $this->maintenances->listForHousehold($household);
    }

    /**
     * Create a household maintenance record.
     *
     * Ownership always comes from the server-resolved household; the
     * client can never set household_id, completed_at, or a status. The
     * referenced asset must resolve inside the same household, otherwise
     * a not-found exception lets the controller mask it as 404.
     *
     * @param  array{asset_id: string, title: string, description?: ?string, location: string, scheduled_date: string, priority: string}  $data
     */
    public function record(Household $household, array $data): Maintenance
    {
        $asset = $this->resolveAsset($household, $data['asset_id']);

        return $this->maintenances->create([
            'household_id' => $household->id,
            'asset_id' => $asset->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'location' => $data['location'],
            'scheduled_date' => $data['scheduled_date'],
            'priority' => $data['priority'],
        ]);
    }

    /**
     * Apply a partial update. Only writable maintenance columns are
     * considered; completed_at and status stay server-managed, so a
     * client payload can never forge the lifecycle. A supplied asset_id
     * must resolve inside the same household.
     *
     * @param  array{asset_id?: string, title?: string, description?: ?string, location?: string, scheduled_date?: string, priority?: string}  $data
     */
    public function change(Household $household, Maintenance $maintenance, array $data): Maintenance
    {
        $attributes = [];

        foreach (['title', 'description', 'location', 'scheduled_date', 'priority'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if (array_key_exists('asset_id', $data)) {
            $attributes['asset_id'] = $this->resolveAsset($household, $data['asset_id'])->id;
        }

        if ($attributes === []) {
            return $maintenance;
        }

        return $this->maintenances->update($maintenance, $attributes);
    }

    /**
     * Mark a scheduled maintenance as completed. completed_at is always
     * the server date; repeating the action on an already-completed
     * record is rejected so the lifecycle stays one-way and consistent.
     */
    public function complete(Maintenance $maintenance): Maintenance
    {
        if ($maintenance->completed_at !== null) {
            throw new MaintenanceAlreadyCompletedException;
        }

        return $this->maintenances->update($maintenance, [
            'completed_at' => now()->format('Y-m-d'),
        ]);
    }

    /**
     * Delete the maintenance row. Deleting the parent asset only detaches
     * maintenance (`asset_id` is ON DELETE SET NULL); it is never removed
     * from here.
     */
    public function remove(Maintenance $maintenance): void
    {
        $this->maintenances->delete($maintenance);
    }

    private function resolveAsset(Household $household, string $assetId): Asset
    {
        $asset = $this->assets->findByIdForHousehold($household, $assetId);

        if ($asset === null) {
            throw new AssetNotFoundException;
        }

        return $asset;
    }
}
