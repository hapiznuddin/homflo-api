<?php

namespace App\Repositories\Contracts;

use App\Models\Household;
use App\Models\Maintenance;
use Illuminate\Database\Eloquent\Collection;

interface MaintenanceRepositoryInterface
{
    public function findByIdForHousehold(Household $household, string $maintenanceId): ?Maintenance;

    /**
     * @return Collection<int, Maintenance>
     */
    public function listForHousehold(Household $household): Collection;

    public function create(array $data): Maintenance;

    public function update(Maintenance $maintenance, array $data): Maintenance;

    public function delete(Maintenance $maintenance): void;
}
