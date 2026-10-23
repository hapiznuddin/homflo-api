<?php

namespace App\Services\Vehicle;

use App\Exceptions\VehicleMaintenanceAlreadyCompletedException;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use App\Repositories\Contracts\VehicleMaintenanceRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class VehicleMaintenanceService
{
    public function __construct(
        private readonly VehicleMaintenanceRepositoryInterface $maintenances,
    ) {}

    /**
     * @return Collection<int, VehicleMaintenance>
     */
    public function listForVehicle(Vehicle $vehicle): Collection
    {
        return $this->maintenances->listForVehicle($vehicle);
    }

    /**
     * Create a service record for a vehicle.
     *
     * The vehicle always comes from the server-resolved household context,
     * so the client can never point the record at a foreign vehicle.
     * completed_at is server-managed (via the Complete action) and is never
     * taken from the payload, so a fresh record is always Scheduled.
     *
     * @param  array{title: string, description?: ?string, scheduled_date: string, odometer?: ?int}  $data
     */
    public function record(Vehicle $vehicle, array $data): VehicleMaintenance
    {
        return $this->maintenances->create([
            'vehicle_id' => $vehicle->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'scheduled_date' => $data['scheduled_date'],
            'odometer' => $data['odometer'] ?? null,
        ]);
    }

    /**
     * Apply a partial update. Only writable columns are considered;
     * completed_at and the derived status stay server-managed, so a client
     * payload can never forge the lifecycle. vehicle_id is never accepted:
     * a record cannot be moved to another vehicle.
     *
     * @param  array{title?: string, description?: ?string, scheduled_date?: string, odometer?: ?int}  $data
     */
    public function change(Vehicle $vehicle, VehicleMaintenance $maintenance, array $data): VehicleMaintenance
    {
        $attributes = [];

        foreach (['title', 'description', 'scheduled_date', 'odometer'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if ($attributes === []) {
            return $maintenance;
        }

        return $this->maintenances->update($maintenance, $attributes);
    }

    /**
     * Delete a single service record. The parent vehicle is never touched.
     */
    public function remove(VehicleMaintenance $maintenance): void
    {
        $this->maintenances->delete($maintenance);
    }

    /**
     * Mark a scheduled service as completed. completed_at is always the
     * server date and the transition runs inside a locked transaction, so
     * concurrent Complete requests cannot double-apply. Repeating the action
     * on a completed record is rejected; there is no cancelled state.
     *
     * @throws VehicleMaintenanceAlreadyCompletedException
     */
    public function complete(VehicleMaintenance $maintenance): VehicleMaintenance
    {
        return DB::transaction(function () use ($maintenance) {
            $locked = VehicleMaintenance::query()
                ->whereKey($maintenance->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->lifecycleStatus() === VehicleMaintenance::STATUS_COMPLETED) {
                throw new VehicleMaintenanceAlreadyCompletedException;
            }

            return $this->maintenances->update($locked, [
                'completed_at' => now()->format('Y-m-d'),
            ]);
        });
    }
}
