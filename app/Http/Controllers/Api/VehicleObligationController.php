<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ObligationTypeNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreVehicleObligationRequest;
use App\Http\Requests\Api\UpdateVehicleObligationRequest;
use App\Models\Household;
use App\Models\Vehicle;
use App\Models\VehicleObligation;
use App\Repositories\Contracts\VehicleObligationRepositoryInterface;
use App\Repositories\Contracts\VehicleRepositoryInterface;
use App\Services\Household\HouseholdService;
use App\Services\Vehicle\VehicleObligationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class VehicleObligationController extends Controller
{
    public function __construct(
        private readonly VehicleObligationService $obligationService,
        private readonly HouseholdService $householdService,
        private readonly VehicleRepositoryInterface $vehicles,
        private readonly VehicleObligationRepositoryInterface $obligations,
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

        if (! Gate::allows('viewAny', [VehicleObligation::class, $household])) {
            return $this->vehicleNotFound();
        }

        $records = $this->obligationService->listForVehicle($resolvedVehicle);

        return response()->json([
            'success' => true,
            'data' => $records->map(fn (VehicleObligation $record) => $this->presentObligation($record))->all(),
        ]);
    }

    public function store(StoreVehicleObligationRequest $request, Vehicle $vehicle): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        $resolvedVehicle = $this->resolveVehicle($household, $vehicle);

        if ($resolvedVehicle === null) {
            return $this->vehicleNotFound();
        }

        if (! Gate::allows('create', [VehicleObligation::class, $resolvedVehicle])) {
            return $this->vehicleNotFound();
        }

        try {
            $record = $this->obligationService->record($resolvedVehicle, $request->validated());
        } catch (ObligationTypeNotFoundException $exception) {
            return $this->typeNotFound($exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentObligation($record),
        ], 201);
    }

    public function show(Vehicle $vehicle, VehicleObligation $obligation): JsonResponse
    {
        $resolved = $this->resolveRecord($vehicle, $obligation);

        if ($resolved === null) {
            return $this->obligationNotFound();
        }

        if (! Gate::allows('view', $resolved)) {
            return $this->obligationNotFound();
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentObligation($resolved),
        ]);
    }

    public function update(UpdateVehicleObligationRequest $request, Vehicle $vehicle, VehicleObligation $obligation): JsonResponse
    {
        $resolved = $this->resolveRecord($vehicle, $obligation);

        if ($resolved === null) {
            return $this->obligationNotFound();
        }

        if (! Gate::allows('update', $resolved)) {
            return $this->obligationNotFound();
        }

        try {
            $updated = $this->obligationService->change($vehicle, $resolved, $request->validated());
        } catch (ObligationTypeNotFoundException $exception) {
            return $this->typeNotFound($exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentObligation($updated),
        ]);
    }

    public function destroy(Vehicle $vehicle, VehicleObligation $obligation): JsonResponse
    {
        $resolved = $this->resolveRecord($vehicle, $obligation);

        if ($resolved === null) {
            return $this->obligationNotFound();
        }

        if (! Gate::allows('delete', $resolved)) {
            return $this->obligationNotFound();
        }

        $this->obligationService->remove($resolved);

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

    private function resolveVehicle(Household $household, Vehicle $vehicle): ?Vehicle
    {
        return $this->vehicles->findByIdForHousehold($household, $vehicle->id);
    }

    /**
     * Resolve an obligation that belongs to the routed vehicle, and a vehicle
     * that belongs to the caller's household. Any mismatch (foreign
     * household, a different vehicle, unknown id) collapses to null and is
     * masked as 404.
     */
    private function resolveRecord(Vehicle $vehicle, VehicleObligation $obligation): ?VehicleObligation
    {
        $household = $this->resolveHousehold(request()->user());

        if ($household === null) {
            return null;
        }

        $resolvedVehicle = $this->resolveVehicle($household, $vehicle);

        if ($resolvedVehicle === null) {
            return null;
        }

        return $this->obligations->findByIdForVehicle($resolvedVehicle, $obligation->id);
    }

    /**
     * @return array{id: string, vehicle_id: string, obligation_type: array{id: string, code: ?string, name: string, is_system: bool}, title: string, description: ?string, issued_date: ?string, due_date: string, amount: ?string, status: string, created_at: mixed, updated_at: mixed}
     */
    private function presentObligation(VehicleObligation $obligation): array
    {
        $obligation->loadMissing('obligationType');

        return [
            'id' => $obligation->id,
            'vehicle_id' => $obligation->vehicle_id,
            'obligation_type' => [
                'id' => $obligation->obligationType->id,
                'code' => $obligation->obligationType->code,
                'name' => $obligation->obligationType->name,
                'is_system' => (bool) $obligation->obligationType->is_system,
            ],
            'title' => $obligation->title,
            'description' => $obligation->description,
            'issued_date' => $obligation->issued_date?->format('Y-m-d'),
            'due_date' => $obligation->due_date->format('Y-m-d'),
            'amount' => $obligation->amount === null ? null : (string) $obligation->amount,
            'status' => $obligation->status,
            'created_at' => $obligation->created_at,
            'updated_at' => $obligation->updated_at,
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

    private function obligationNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'VEHICLE_OBLIGATION_NOT_FOUND',
                'message' => 'Vehicle obligation not found.',
            ],
        ], 404);
    }

    private function typeNotFound(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'OBLIGATION_TYPE_NOT_FOUND',
                'message' => $message,
            ],
        ], 422);
    }
}
