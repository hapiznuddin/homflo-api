<?php

namespace App\Repositories;

use App\Models\Household;
use App\Models\Vehicle;
use App\Repositories\Contracts\VehicleRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class VehicleRepository implements VehicleRepositoryInterface
{
    public function findByIdForHousehold(Household $household, string $vehicleId): ?Vehicle
    {
        return Vehicle::query()
            ->where('household_id', $household->id)
            ->where('id', $vehicleId)
            ->first();
    }

    /**
     * @return Collection<int, Vehicle>
     */
    public function listForHousehold(Household $household): Collection
    {
        return Vehicle::query()
            ->where('household_id', $household->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function existsByPlateForHousehold(Household $household, string $plateNumber, ?string $exceptId = null): bool
    {
        $query = Vehicle::query()
            ->where('household_id', $household->id)
            ->where('plate_number', $plateNumber);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->exists();
    }

    public function create(array $data): Vehicle
    {
        return Vehicle::query()->create($data);
    }

    public function update(Vehicle $vehicle, array $data): Vehicle
    {
        $vehicle->update($data);

        return $vehicle->refresh();
    }

    public function delete(Vehicle $vehicle): void
    {
        $vehicle->delete();
    }
}
