<?php

namespace App\Repositories;

use App\Models\Vehicle;
use App\Models\VehicleObligation;
use App\Repositories\Contracts\VehicleObligationRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class VehicleObligationRepository implements VehicleObligationRepositoryInterface
{
    public function findByIdForVehicle(Vehicle $vehicle, string $obligationId): ?VehicleObligation
    {
        return VehicleObligation::query()
            ->with('obligationType')
            ->where('vehicle_id', $vehicle->id)
            ->whereKey($obligationId)
            ->first();
    }

    /**
     * @return Collection<int, VehicleObligation>
     */
    public function listForVehicle(Vehicle $vehicle): Collection
    {
        return VehicleObligation::query()
            ->with('obligationType')
            ->where('vehicle_id', $vehicle->id)
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
    }

    public function create(array $data): VehicleObligation
    {
        return VehicleObligation::query()->create($data)->load('obligationType');
    }

    public function update(VehicleObligation $obligation, array $data): VehicleObligation
    {
        $obligation->update($data);

        return $obligation->refresh()->load('obligationType');
    }

    public function delete(VehicleObligation $obligation): void
    {
        $obligation->delete();
    }
}
