<?php

namespace App\Repositories\Contracts;

use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use Illuminate\Database\Eloquent\Collection;

interface VehicleMaintenanceRepositoryInterface
{
    public function findByIdForVehicle(Vehicle $vehicle, string $maintenanceId): ?VehicleMaintenance;

    /**
     * @return Collection<int, VehicleMaintenance>
     */
    public function listForVehicle(Vehicle $vehicle): Collection;

    public function create(array $data): VehicleMaintenance;

    public function update(VehicleMaintenance $maintenance, array $data): VehicleMaintenance;

    public function delete(VehicleMaintenance $maintenance): void;

    public function countForVehicle(Vehicle $vehicle): int;

    public function maxOdometerForVehicle(Vehicle $vehicle): ?int;
}
