<?php

namespace App\Repositories\Contracts;

use App\Models\Household;
use App\Models\ObligationType;
use Illuminate\Database\Eloquent\Collection;

interface ObligationTypeRepositoryInterface
{
    public function findAvailableToHouseholdById(Household $household, string $typeId): ?ObligationType;

    public function findCustomForHouseholdById(Household $household, string $typeId): ?ObligationType;

    /**
     * @return Collection<int, ObligationType>
     */
    public function listForHousehold(Household $household): Collection;

    public function existsByNameForHousehold(Household $household, string $name, ?string $exceptId = null): bool;

    public function create(array $data): ObligationType;

    public function update(ObligationType $type, array $data): ObligationType;

    public function delete(ObligationType $type): void;
}
