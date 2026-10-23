<?php

namespace App\Services\Vehicle;

use App\Exceptions\VehicleHasMaintenanceHistoryException;
use App\Exceptions\VehicleOdometerBelowRecordedException;
use App\Exceptions\VehiclePlateAlreadyExistsException;
use App\Models\Household;
use App\Models\Vehicle;
use App\Repositories\Contracts\VehicleMaintenanceRepositoryInterface;
use App\Repositories\Contracts\VehicleRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class VehicleService
{
    public function __construct(
        private readonly VehicleRepositoryInterface $vehicles,
        private readonly VehicleMaintenanceRepositoryInterface $vehicleMaintenances,
    ) {}

    /**
     * @return Collection<int, Vehicle>
     */
    public function listForHousehold(Household $household): Collection
    {
        return $this->vehicles->listForHousehold($household);
    }

    /**
     * Create a vehicle in the household catalogue.
     *
     * Ownership always comes from the server-resolved household; the client
     * can never set household_id. A plate number that already exists in the
     * same household is rejected by the unique index, so it surfaces as a
     * domain conflict instead of a 500.
     *
     * @param  array{name: string, brand?: ?string, model?: ?string, year?: ?int, type?: ?string, plate_number?: ?string, odometer?: ?int}  $data
     *
     * @throws VehiclePlateAlreadyExistsException
     */
    public function record(Household $household, array $data): Vehicle
    {
        $plateNumber = $data['plate_number'] ?? null;

        if ($plateNumber !== null
            && $this->vehicles->existsByPlateForHousehold($household, $plateNumber)) {
            throw new VehiclePlateAlreadyExistsException;
        }

        return $this->vehicles->create([
            'household_id' => $household->id,
            'name' => $data['name'],
            'brand' => $data['brand'] ?? null,
            'model' => $data['model'] ?? null,
            'year' => $data['year'] ?? null,
            'type' => $data['type'] ?? null,
            'plate_number' => $plateNumber,
            'odometer' => $data['odometer'] ?? null,
        ]);
    }

    /**
     * Apply a partial update. Only writable vehicle columns are considered;
     * omitted keys stay untouched and nullable columns accept an explicit
     * null so a recorded value can be cleared. A plate number must stay
     * unique per household, and the current odometer may not drop below any
     * odometer already recorded on the vehicle's service history.
     *
     * @param  array{name?: string, brand?: ?string, model?: ?string, year?: ?int, type?: ?string, plate_number?: ?string, odometer?: ?int}  $data
     *
     * @throws VehiclePlateAlreadyExistsException
     * @throws VehicleOdometerBelowRecordedException
     */
    public function change(Household $household, Vehicle $vehicle, array $data): Vehicle
    {
        if (array_key_exists('plate_number', $data) && $data['plate_number'] !== null
            && $this->vehicles->existsByPlateForHousehold($household, $data['plate_number'], $vehicle->id)) {
            throw new VehiclePlateAlreadyExistsException;
        }

        if (array_key_exists('odometer', $data) && $data['odometer'] !== null) {
            $this->assertOdometerNotBelowRecorded($vehicle, (int) $data['odometer']);
        }

        $attributes = [];

        foreach (['name', 'brand', 'model', 'year', 'type', 'plate_number', 'odometer'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if ($attributes === []) {
            return $vehicle;
        }

        return $this->vehicles->update($vehicle, $attributes);
    }

    /**
     * Delete a vehicle that has no service history.
     *
     * The product rule forbids deleting a vehicle while it still has
     * `vehicle_maintenance` rows, so the guard runs inside a transaction
     * that locks the vehicle row: a concurrent service insert (its foreign
     * key check takes a shared lock on the vehicle row) is serialized
     * against the delete, keeping the check and the delete atomic. History
     * is never removed from here.
     *
     * @throws VehicleHasMaintenanceHistoryException
     */
    public function remove(Vehicle $vehicle): void
    {
        DB::transaction(function () use ($vehicle): void {
            $locked = Vehicle::query()
                ->whereKey($vehicle->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return;
            }

            if ($this->vehicleMaintenances->countForVehicle($locked) > 0) {
                throw new VehicleHasMaintenanceHistoryException;
            }

            $this->vehicles->delete($locked);
        });
    }

    private function assertOdometerNotBelowRecorded(Vehicle $vehicle, int $odometer): void
    {
        $recorded = $this->vehicleMaintenances->maxOdometerForVehicle($vehicle);

        if ($recorded !== null && $odometer < $recorded) {
            throw new VehicleOdometerBelowRecordedException;
        }
    }
}
