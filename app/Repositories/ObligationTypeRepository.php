<?php

namespace App\Repositories;

use App\Models\Household;
use App\Models\ObligationType;
use App\Repositories\Contracts\ObligationTypeRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class ObligationTypeRepository implements ObligationTypeRepositoryInterface
{
    public function findAvailableToHouseholdById(Household $household, string $typeId): ?ObligationType
    {
        return ObligationType::query()
            ->availableToHousehold($household)
            ->whereKey($typeId)
            ->first();
    }

    public function findCustomForHouseholdById(Household $household, string $typeId): ?ObligationType
    {
        return ObligationType::query()
            ->where('household_id', $household->id)
            ->where('is_system', false)
            ->whereKey($typeId)
            ->first();
    }

    /**
     * @return Collection<int, ObligationType>
     */
    public function listForHousehold(Household $household): Collection
    {
        return ObligationType::query()
            ->availableToHousehold($household)
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function existsByNameForHousehold(Household $household, string $name, ?string $exceptId = null): bool
    {
        $query = ObligationType::query()
            ->availableToHousehold($household)
            ->where('name', $name);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        return $query->exists();
    }

    public function create(array $data): ObligationType
    {
        return ObligationType::query()->create($data);
    }

    public function update(ObligationType $type, array $data): ObligationType
    {
        $type->update($data);

        return $type->refresh();
    }

    public function delete(ObligationType $type): void
    {
        $type->delete();
    }
}
