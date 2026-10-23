<?php

namespace App\Repositories;

use App\Models\Household;
use App\Models\Maintenance;
use App\Repositories\Contracts\MaintenanceRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class MaintenanceRepository implements MaintenanceRepositoryInterface
{
    public function findByIdForHousehold(Household $household, string $maintenanceId): ?Maintenance
    {
        return Maintenance::query()
            ->where('household_id', $household->id)
            ->where('id', $maintenanceId)
            ->first();
    }

    /**
     * @return Collection<int, Maintenance>
     */
    public function listForHousehold(Household $household): Collection
    {
        return Maintenance::query()
            ->where('household_id', $household->id)
            ->orderBy('scheduled_date')
            ->orderBy('id')
            ->get();
    }

    public function create(array $data): Maintenance
    {
        return Maintenance::query()->create($data);
    }

    public function update(Maintenance $maintenance, array $data): Maintenance
    {
        $maintenance->update($data);

        return $maintenance->refresh();
    }

    public function delete(Maintenance $maintenance): void
    {
        $maintenance->delete();
    }
}
