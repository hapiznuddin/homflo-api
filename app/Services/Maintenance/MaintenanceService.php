<?php

namespace App\Services\Maintenance;

use App\Exceptions\AssetNotFoundException;
use App\Exceptions\MaintenanceAlreadyCancelledException;
use App\Exceptions\MaintenanceAlreadyCompletedException;
use App\Models\Asset;
use App\Models\Household;
use App\Models\Maintenance;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Repositories\Contracts\MaintenanceRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

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
     * the server date and the lifecycle transition runs inside a locked
     * transaction, so concurrent Complete requests cannot double-apply.
     * Repeating the action on a completed record is rejected, and a
     * cancelled record can never be completed.
     */
    public function complete(Maintenance $maintenance): Maintenance
    {
        return DB::transaction(function () use ($maintenance) {
            $locked = $this->lock($maintenance);

            if ($locked->lifecycleStatus() === Maintenance::STATUS_CANCELLED) {
                throw new MaintenanceAlreadyCancelledException;
            }

            if ($locked->lifecycleStatus() === Maintenance::STATUS_COMPLETED) {
                throw new MaintenanceAlreadyCompletedException;
            }

            return $this->maintenances->update($locked, [
                'completed_at' => now()->format('Y-m-d'),
            ]);
        });
    }

    /**
     * Cancel a scheduled maintenance. cancelled_at is always the server
     * date and the transition runs inside a locked transaction, so
     * concurrent Cancel requests cannot double-apply. A completed record
     * can never be cancelled, and a cancelled record stays cancelled.
     */
    public function cancel(Maintenance $maintenance): Maintenance
    {
        return DB::transaction(function () use ($maintenance) {
            $locked = $this->lock($maintenance);

            $status = $locked->lifecycleStatus();

            if ($status === Maintenance::STATUS_COMPLETED) {
                throw new MaintenanceAlreadyCompletedException;
            }

            if ($status === Maintenance::STATUS_CANCELLED) {
                throw new MaintenanceAlreadyCancelledException;
            }

            return $this->maintenances->update($locked, [
                'cancelled_at' => now()->format('Y-m-d'),
            ]);
        });
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

    /**
     * Re-read the row under a `SELECT ... FOR UPDATE` lock so a lifecycle
     * transition is serialized against concurrent requests. On SQLite
     * (tests) the lock clause is a no-op but the transaction still keeps
     * the guard and write atomic.
     */
    private function lock(Maintenance $maintenance): Maintenance
    {
        return Maintenance::query()
            ->whereKey($maintenance->id)
            ->lockForUpdate()
            ->firstOrFail();
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
