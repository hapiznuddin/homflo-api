<?php

namespace App\Repositories\Contracts;

use App\Models\Vehicle;
use App\Models\VehicleObligation;
use Illuminate\Database\Eloquent\Collection;

interface VehicleObligationRepositoryInterface
{
    public function findByIdForVehicle(Vehicle $vehicle, string $obligationId): ?VehicleObligation;

    /**
     * @return Collection<int, VehicleObligation>
     */
    public function listForVehicle(Vehicle $vehicle): Collection;

    public function create(array $data): VehicleObligation;

    public function update(VehicleObligation $obligation, array $data): VehicleObligation;

    public function delete(VehicleObligation $obligation): void;
}
