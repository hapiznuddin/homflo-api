<?php

namespace App\Repositories;

use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use App\Repositories\Contracts\VehicleMaintenanceRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class VehicleMaintenanceRepository implements VehicleMaintenanceRepositoryInterface
{
    public function findByIdForVehicle(Vehicle $vehicle, string $maintenanceId): ?VehicleMaintenance
    {
        return VehicleMaintenance::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('id', $maintenanceId)
            ->first();
    }

    /**
     * @return Collection<int, VehicleMaintenance>
     */
    public function listForVehicle(Vehicle $vehicle): Collection
    {
        return VehicleMaintenance::query()
            ->where('vehicle_id', $vehicle->id)
            ->orderBy('scheduled_date')
            ->orderBy('id')
            ->get();
    }

    public function create(array $data): VehicleMaintenance
    {
        return VehicleMaintenance::query()->create($data);
    }

    public function update(VehicleMaintenance $maintenance, array $data): VehicleMaintenance
    {
        $maintenance->update($data);

        return $maintenance->refresh();
    }

    public function delete(VehicleMaintenance $maintenance): void
    {
        $maintenance->delete();
    }

    public function countForVehicle(Vehicle $vehicle): int
    {
        return VehicleMaintenance::query()
            ->where('vehicle_id', $vehicle->id)
            ->count();
    }

    public function maxOdometerForVehicle(Vehicle $vehicle): ?int
    {
        $max = VehicleMaintenance::query()
            ->where('vehicle_id', $vehicle->id)
            ->max('odometer');

        return $max === null ? null : (int) $max;
    }
}
