<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\VehicleMaintenanceAlreadyCompletedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreVehicleMaintenanceRequest;
use App\Http\Requests\Api\UpdateVehicleMaintenanceRequest;
use App\Models\Household;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use App\Repositories\Contracts\VehicleMaintenanceRepositoryInterface;
use App\Repositories\Contracts\VehicleRepositoryInterface;
use App\Services\Household\HouseholdService;
use App\Services\Vehicle\VehicleMaintenanceService;
use Illuminate\Database\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class VehicleMaintenanceController extends Controller
{
    public function __construct(
        private readonly VehicleMaintenanceService $maintenanceService,
        private readonly HouseholdService $householdService,
        private readonly VehicleRepositoryInterface $vehicles,
        private readonly VehicleMaintenanceRepositoryInterface $maintenances,
    ) {}

    public function index(Request $request, Vehicle $vehicle): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        $resolvedVehicle = $this->resolveVehicle($household, $vehicle);

        if ($resolvedVehicle === null) {
            return $this->vehicleNotFound();
        }

        if (! Gate::allows('viewAny', [VehicleMaintenance::class, $household])) {
            return $this->vehicleNotFound();
        }

        $records = $this->maintenanceService->listForVehicle($resolvedVehicle);

        return response()->json([
            'success' => true,
            'data' => $records->map(fn (VehicleMaintenance $record) => $this->presentMaintenance($record))->all(),
        ]);
    }

    public function store(StoreVehicleMaintenanceRequest $request, Vehicle $vehicle): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        $resolvedVehicle = $this->resolveVehicle($household, $vehicle);

        if ($resolvedVehicle === null) {
            return $this->vehicleNotFound();
        }

        if (! Gate::allows('create', [VehicleMaintenance::class, $resolvedVehicle])) {
            return $this->vehicleNotFound();
        }

        $record = $this->maintenanceService->record($resolvedVehicle, $request->validated());

        return response()->json([
            'success' => true,
            'data' => $this->presentMaintenance($record),
        ], 201);
    }

    public function show(Vehicle $vehicle, VehicleMaintenance $maintenance): JsonResponse
    {
        $resolved = $this->resolveRecord($vehicle, $maintenance);

        if ($resolved === null) {
            return $this->maintenanceNotFound();
        }

        if (! Gate::allows('view', $resolved)) {
            return $this->maintenanceNotFound();
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentMaintenance($resolved),
        ]);
    }

    public function update(UpdateVehicleMaintenanceRequest $request, Vehicle $vehicle, VehicleMaintenance $maintenance): JsonResponse
    {
        $resolved = $this->resolveRecord($vehicle, $maintenance);

        if ($resolved === null) {
            return $this->maintenanceNotFound();
        }

        if (! Gate::allows('update', $resolved)) {
            return $this->maintenanceNotFound();
        }

        $updated = $this->maintenanceService->change($vehicle, $resolved, $request->validated());

        return response()->json([
            'success' => true,
            'data' => $this->presentMaintenance($updated),
        ]);
    }

    public function destroy(Vehicle $vehicle, VehicleMaintenance $maintenance): JsonResponse
    {
        $resolved = $this->resolveRecord($vehicle, $maintenance);

        if ($resolved === null) {
            return $this->maintenanceNotFound();
        }

        if (! Gate::allows('delete', $resolved)) {
            return $this->maintenanceNotFound();
        }

        $this->maintenanceService->remove($resolved);

        return response()->json([
            'success' => true,
            'data' => ['id' => $resolved->id],
        ]);
    }

    /**
     * Complete action: a dedicated one-way endpoint so completed_at is
     * always set server-side and never accepted from the payload. Only a
     * scheduled record can be completed; there is no cancelled state.
     */
    public function complete(Vehicle $vehicle, VehicleMaintenance $maintenance): JsonResponse
    {
        $resolved = $this->resolveRecord($vehicle, $maintenance);

        if ($resolved === null) {
            return $this->maintenanceNotFound();
        }

        if (! Gate::allows('complete', $resolved)) {
            return $this->maintenanceNotFound();
        }

        try {
            $completed = $this->maintenanceService->complete($resolved);
        } catch (ModelNotFoundException) {
            // The row vanished between the scoped resolve and the lock —
            // surface the same 404 mask as a missing record.
            return $this->maintenanceNotFound();
        } catch (VehicleMaintenanceAlreadyCompletedException $exception) {
            return $this->unprocessable('VEHICLE_MAINTENANCE_ALREADY_COMPLETED', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentMaintenance($completed),
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

    private function resolveVehicle(Household $household, Vehicle $vehicle): ?Vehicle
    {
        return $this->vehicles->findByIdForHousehold($household, $vehicle->id);
    }

    /**
     * Resolve a service record that belongs to the given vehicle, and a
     * vehicle that belongs to the caller's household. Any mismatch (foreign
     * household, different vehicle, unknown id) collapses to null so the
     * controller can mask it as 404.
     */
    private function resolveRecord(Vehicle $vehicle, VehicleMaintenance $maintenance): ?VehicleMaintenance
    {
        $household = $this->resolveHousehold(request()->user());

        if ($household === null) {
            return null;
        }

        $resolvedVehicle = $this->resolveVehicle($household, $vehicle);

        if ($resolvedVehicle === null) {
            return null;
        }

        return $this->maintenances->findByIdForVehicle($resolvedVehicle, $maintenance->id);
    }

    /**
     * @return array{id: string, vehicle_id: string, title: string, description: ?string, scheduled_date: ?string, completed_at: ?string, odometer: ?int, status: string, created_at: mixed, updated_at: mixed}
     */
    private function presentMaintenance(VehicleMaintenance $maintenance): array
    {
        return [
            'id' => $maintenance->id,
            'vehicle_id' => $maintenance->vehicle_id,
            'title' => $maintenance->title,
            'description' => $maintenance->description,
            'scheduled_date' => $maintenance->scheduled_date?->format('Y-m-d'),
            'completed_at' => $maintenance->completed_at?->format('Y-m-d'),
            'odometer' => $maintenance->odometer,
            'status' => $maintenance->lifecycleStatus(),
            'created_at' => $maintenance->created_at,
            'updated_at' => $maintenance->updated_at,
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

    private function maintenanceNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'VEHICLE_MAINTENANCE_NOT_FOUND',
                'message' => 'Vehicle maintenance not found.',
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
}
