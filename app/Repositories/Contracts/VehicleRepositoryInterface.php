<?php

namespace App\Repositories\Contracts;

use App\Models\Household;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Collection;

interface VehicleRepositoryInterface
{
    public function findByIdForHousehold(Household $household, string $vehicleId): ?Vehicle;

    /**
     * @return Collection<int, Vehicle>
     */
    public function listForHousehold(Household $household): Collection;

    public function existsByPlateForHousehold(Household $household, string $plateNumber, ?string $exceptId = null): bool;

    public function create(array $data): Vehicle;

    public function update(Vehicle $vehicle, array $data): Vehicle;

    public function delete(Vehicle $vehicle): void;
}
