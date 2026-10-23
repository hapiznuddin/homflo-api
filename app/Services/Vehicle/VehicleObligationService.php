<?php

namespace App\Services\Vehicle;

use App\Exceptions\ObligationTypeNotFoundException;
use App\Models\Vehicle;
use App\Models\VehicleObligation;
use App\Repositories\Contracts\ObligationTypeRepositoryInterface;
use App\Repositories\Contracts\VehicleObligationRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class VehicleObligationService
{
    public function __construct(
        private readonly VehicleObligationRepositoryInterface $obligations,
        private readonly ObligationTypeRepositoryInterface $types,
    ) {}

    /**
     * @return Collection<int, VehicleObligation>
     */
    public function listForVehicle(Vehicle $vehicle): Collection
    {
        return $this->obligations->listForVehicle($vehicle);
    }

    /**
     * Create an administrative obligation for the routed vehicle.
     *
     * The vehicle is always resolved from the server-side household context,
     * and the type must be a system type or a custom type owned by that same
     * household. status starts Active and is not taken from the payload.
     *
     * @param  array{obligation_type_id: string, title: string, description?: ?string, issued_date?: ?string, due_date: string, amount?: int|float|string|null}  $data
     *
     * @throws ObligationTypeNotFoundException
     */
    public function record(Vehicle $vehicle, array $data): VehicleObligation
    {
        $this->assertTypeAvailable($vehicle, $data['obligation_type_id']);

        return $this->obligations->create([
            'vehicle_id' => $vehicle->id,
            'obligation_type_id' => $data['obligation_type_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'issued_date' => $data['issued_date'] ?? null,
            'due_date' => $data['due_date'],
            'amount' => $data['amount'] ?? null,
            'status' => VehicleObligation::STATUS_ACTIVE,
        ]);
    }

    /**
     * Apply a partial update. vehicle_id is never accepted, so an obligation
     * cannot be moved to another vehicle or household. Type changes are
     * checked against the vehicle's household, and status accepts only the
     * two administrative values validated by the request.
     *
     * @param  array{obligation_type_id?: string, title?: string, description?: ?string, issued_date?: ?string, due_date?: string, amount?: int|float|string|null, status?: string}  $data
     *
     * @throws ObligationTypeNotFoundException
     */
    public function change(Vehicle $vehicle, VehicleObligation $obligation, array $data): VehicleObligation
    {
        if (array_key_exists('obligation_type_id', $data)) {
            $this->assertTypeAvailable($vehicle, $data['obligation_type_id']);
        }

        $attributes = [];

        foreach (['obligation_type_id', 'title', 'description', 'issued_date', 'due_date', 'amount', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if ($attributes === []) {
            return $obligation;
        }

        return $this->obligations->update($obligation, $attributes);
    }

    /**
     * Delete a single obligation. The vehicle and its other obligations are
     * never touched.
     */
    public function remove(VehicleObligation $obligation): void
    {
        $this->obligations->delete($obligation);
    }

    /**
     * @throws ObligationTypeNotFoundException
     */
    private function assertTypeAvailable(Vehicle $vehicle, string $typeId): void
    {
        $household = $vehicle->household;

        if ($household === null
            || $this->types->findAvailableToHouseholdById($household, $typeId) === null) {
            throw new ObligationTypeNotFoundException;
        }
    }
}
