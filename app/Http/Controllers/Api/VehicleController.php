<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\VehicleHasMaintenanceHistoryException;
use App\Exceptions\VehicleOdometerBelowRecordedException;
use App\Exceptions\VehiclePlateAlreadyExistsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreVehicleRequest;
use App\Http\Requests\Api\UpdateVehicleRequest;
use App\Models\Household;
use App\Models\Vehicle;
use App\Repositories\Contracts\VehicleRepositoryInterface;
use App\Services\Household\HouseholdService;
use App\Services\Vehicle\VehicleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class VehicleController extends Controller
{
    public function __construct(
        private readonly VehicleService $vehicleService,
        private readonly HouseholdService $householdService,
        private readonly VehicleRepositoryInterface $vehicles,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('viewAny', [Vehicle::class, $household])) {
            return $this->householdNotFound();
        }

        $vehicles = $this->vehicleService->listForHousehold($household);

        return response()->json([
            'success' => true,
            'data' => $vehicles->map(fn (Vehicle $vehicle) => $this->presentVehicle($vehicle))->all(),
        ]);
    }

    public function store(StoreVehicleRequest $request): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('create', [Vehicle::class, $household])) {
            return $this->householdNotFound();
        }

        try {
            $vehicle = $this->vehicleService->record($household, $request->validated());
        } catch (VehiclePlateAlreadyExistsException $exception) {
            return $this->unprocessable('VEHICLE_PLATE_ALREADY_EXISTS', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentVehicle($vehicle),
        ], 201);
    }

    public function show(Vehicle $vehicle): JsonResponse
    {
        $resolved = $this->resolveVehicle($vehicle);

        if ($resolved === null) {
            return $this->vehicleNotFound();
        }

        if (! Gate::allows('view', $resolved)) {
            return $this->vehicleNotFound();
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentVehicle($resolved),
        ]);
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle): JsonResponse
    {
        $resolved = $this->resolveVehicle($vehicle);

        if ($resolved === null) {
            return $this->vehicleNotFound();
        }

        if (! Gate::allows('update', $resolved)) {
            return $this->vehicleNotFound();
        }

        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        try {
            $updated = $this->vehicleService->change($household, $resolved, $request->validated());
        } catch (VehiclePlateAlreadyExistsException $exception) {
            return $this->unprocessable('VEHICLE_PLATE_ALREADY_EXISTS', $exception->getMessage());
        } catch (VehicleOdometerBelowRecordedException $exception) {
            return $this->unprocessable('VEHICLE_ODOMETER_BELOW_RECORDED', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentVehicle($updated),
        ]);
    }

    public function destroy(Vehicle $vehicle): JsonResponse
    {
        $resolved = $this->resolveVehicle($vehicle);

        if ($resolved === null) {
            return $this->vehicleNotFound();
        }

        if (! Gate::allows('delete', $resolved)) {
            return $this->vehicleNotFound();
        }

        try {
            $this->vehicleService->remove($resolved);
        } catch (VehicleHasMaintenanceHistoryException $exception) {
            return $this->conflict('VEHICLE_HAS_MAINTENANCE_HISTORY', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => ['id' => $resolved->id],
        ]);
    }

    private function resolveHousehold(mixed $user): ?Household
    {
        $context = $this->householdService->resolveUserHouseholdContext($user);

        if ($context['household'] === null) {
            return null;
        }

        return Household::query()->find($context['household']['id']);
    }

    private function resolveVehicle(Vehicle $vehicle): ?Vehicle
    {
        $context = $this->householdService->resolveUserHouseholdContext(request()->user());

        if ($context['household'] === null) {
            return null;
        }

        if ($vehicle->household_id !== $context['household']['id']) {
            return null;
        }

        $household = Household::query()->find($context['household']['id']);

        if ($household === null) {
            return null;
        }

        return $this->vehicles->findByIdForHousehold($household, $vehicle->id);
    }

    /**
     * @return array{id: string, household_id: string, name: string, brand: ?string, model: ?string, year: ?int, type: ?string, plate_number: ?string, odometer: ?int, created_at: mixed, updated_at: mixed}
     */
    private function presentVehicle(Vehicle $vehicle): array
    {
        return [
            'id' => $vehicle->id,
            'household_id' => $vehicle->household_id,
            'name' => $vehicle->name,
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'year' => $vehicle->year,
            'type' => $vehicle->type,
            'plate_number' => $vehicle->plate_number,
            'odometer' => $vehicle->odometer,
            'created_at' => $vehicle->created_at,
            'updated_at' => $vehicle->updated_at,
        ];
    }

    private function householdNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'HOUSEHOLD_NOT_FOUND',
                'message' => 'Household not found.',
            ],
        ], 404);
    }

    private function vehicleNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'VEHICLE_NOT_FOUND',
                'message' => 'Vehicle not found.',
            ],
        ], 404);
    }

    private function unprocessable(string $code, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], 422);
    }

    private function conflict(string $code, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], 409);
    }
}
