<?php

namespace App\Services\Vehicle;

use App\Exceptions\ObligationTypeAlreadyExistsException;
use App\Exceptions\ObligationTypeImmutableException;
use App\Exceptions\ObligationTypeInUseException;
use App\Models\Household;
use App\Models\ObligationType;
use App\Repositories\Contracts\ObligationTypeRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

class ObligationTypeService
{
    public function __construct(
        private readonly ObligationTypeRepositoryInterface $types,
    ) {}

    /**
     * @return Collection<int, ObligationType>
     */
    public function listForHousehold(Household $household): Collection
    {
        return $this->types->listForHousehold($household);
    }

    /**
     * Add a household-owned custom type. System types are seeded by migration
     * and can never be created through the API.
     *
     * @param  array{name: string}  $data
     *
     * @throws ObligationTypeAlreadyExistsException
     */
    public function record(Household $household, array $data): ObligationType
    {
        $name = trim($data['name']);

        if ($this->types->existsByNameForHousehold($household, $name)) {
            throw new ObligationTypeAlreadyExistsException;
        }

        try {
            return $this->types->create([
                'household_id' => $household->id,
                'code' => null,
                'name' => $name,
                'is_system' => false,
            ]);
        } catch (QueryException) {
            // A concurrent create can lose the unique-name race. Surface the
            // same domain conflict instead of a 500.
            throw new ObligationTypeAlreadyExistsException;
        }
    }

    /**
     * Rename a custom type. Built-in types are immutable by contract.
     *
     * @param  array{name?: string}  $data
     *
     * @throws ObligationTypeImmutableException
     * @throws ObligationTypeAlreadyExistsException
     */
    public function change(Household $household, ObligationType $type, array $data): ObligationType
    {
        if ($type->is_system) {
            throw new ObligationTypeImmutableException;
        }

        if (! array_key_exists('name', $data)) {
            return $type;
        }

        $name = trim($data['name']);

        if ($this->types->existsByNameForHousehold($household, $name, $type->id)) {
            throw new ObligationTypeAlreadyExistsException;
        }

        try {
            return $this->types->update($type, ['name' => $name]);
        } catch (QueryException) {
            throw new ObligationTypeAlreadyExistsException;
        }
    }

    /**
     * Delete an unused custom type. The NO ACTION foreign key backstops the
     * service guard so a referenced type is never silently removed or
     * detached.
     *
     * @throws ObligationTypeImmutableException
     * @throws ObligationTypeInUseException
     */
    public function remove(ObligationType $type): void
    {
        if ($type->is_system) {
            throw new ObligationTypeImmutableException;
        }

        try {
            $this->types->delete($type);
        } catch (QueryException) {
            throw new ObligationTypeInUseException;
        }
    }
}
