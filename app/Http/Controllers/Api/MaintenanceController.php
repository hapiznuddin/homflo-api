<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AssetNotFoundException;
use App\Exceptions\MaintenanceAlreadyCancelledException;
use App\Exceptions\MaintenanceAlreadyCompletedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreMaintenanceRequest;
use App\Http\Requests\Api\UpdateMaintenanceRequest;
use App\Models\Household;
use App\Models\Maintenance;
use App\Repositories\Contracts\MaintenanceRepositoryInterface;
use App\Services\Household\HouseholdService;
use App\Services\Maintenance\MaintenanceService;
use Illuminate\Database\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MaintenanceController extends Controller
{
    public function __construct(
        private readonly MaintenanceService $maintenanceService,
        private readonly HouseholdService $householdService,
        private readonly MaintenanceRepositoryInterface $maintenances,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('viewAny', [Maintenance::class, $household])) {
            return $this->householdNotFound();
        }

        $maintenances = $this->maintenanceService->listForHousehold($household);

        return response()->json([
            'success' => true,
            'data' => $maintenances->map(fn (Maintenance $maintenance) => $this->presentMaintenance($maintenance))->all(),
        ]);
    }

    public function store(StoreMaintenanceRequest $request): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('create', [Maintenance::class, $household])) {
            return $this->householdNotFound();
        }

        try {
            $maintenance = $this->maintenanceService->record($household, $request->validated());
        } catch (AssetNotFoundException) {
            return $this->assetNotFound();
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentMaintenance($maintenance),
        ], 201);
    }

    public function show(Maintenance $maintenance): JsonResponse
    {
        $resolved = $this->resolveMaintenance($maintenance);

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

    public function update(UpdateMaintenanceRequest $request, Maintenance $maintenance): JsonResponse
    {
        $resolved = $this->resolveMaintenance($maintenance);

        if ($resolved === null) {
            return $this->maintenanceNotFound();
        }

        if (! Gate::allows('update', $resolved)) {
            return $this->maintenanceNotFound();
        }

        $household = $resolved->household;

        if ($household === null) {
            return $this->maintenanceNotFound();
        }

        try {
            $updated = $this->maintenanceService->change($household, $resolved, $request->validated());
        } catch (AssetNotFoundException) {
            return $this->assetNotFound();
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentMaintenance($updated),
        ]);
    }

    public function destroy(Maintenance $maintenance): JsonResponse
    {
        $resolved = $this->resolveMaintenance($maintenance);

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
     * Complete action: a dedicated one-way endpoint (wallet-deactivate
     * precedent) so completed_at is always set server-side and never
     * accepted from the payload. A cancelled record can never be
     * completed. Reschedule has no dedicated endpoint — it reuses the
     * validated PATCH above.
     */
    public function complete(Maintenance $maintenance): JsonResponse
    {
        $resolved = $this->resolveMaintenance($maintenance);

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
            // surface the same 404 mask as a missing maintenance.
            return $this->maintenanceNotFound();
        } catch (MaintenanceAlreadyCancelledException $exception) {
            return $this->unprocessable('MAINTENANCE_ALREADY_CANCELLED', $exception->getMessage());
        } catch (MaintenanceAlreadyCompletedException $exception) {
            return $this->unprocessable('MAINTENANCE_ALREADY_COMPLETED', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentMaintenance($completed),
        ]);
    }

    /**
     * Cancel action: the mirror of Complete. cancelled_at is always set
     * server-side and never accepted from the payload. Only a scheduled
     * record can be cancelled; completed and cancelled records are
     * rejected so the lifecycle stays terminal and one-way.
     */
    public function cancel(Maintenance $maintenance): JsonResponse
    {
        $resolved = $this->resolveMaintenance($maintenance);

        if ($resolved === null) {
            return $this->maintenanceNotFound();
        }

        if (! Gate::allows('cancel', $resolved)) {
            return $this->maintenanceNotFound();
        }

        try {
            $cancelled = $this->maintenanceService->cancel($resolved);
        } catch (ModelNotFoundException) {
            return $this->maintenanceNotFound();
        } catch (MaintenanceAlreadyCompletedException $exception) {
            return $this->unprocessable('MAINTENANCE_ALREADY_COMPLETED', $exception->getMessage());
        } catch (MaintenanceAlreadyCancelledException $exception) {
            return $this->unprocessable('MAINTENANCE_ALREADY_CANCELLED', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentMaintenance($cancelled),
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

    private function resolveMaintenance(Maintenance $maintenance): ?Maintenance
    {
        $context = $this->householdService->resolveUserHouseholdContext(request()->user());

        if ($context['household'] === null) {
            return null;
        }

        if ($maintenance->household_id !== $context['household']['id']) {
            return null;
        }

        $household = Household::query()->find($context['household']['id']);

        if ($household === null) {
            return null;
        }

        return $this->maintenances->findByIdForHousehold($household, $maintenance->id);
    }

    /**
     * Present one maintenance record. `status` is derived per request from
     * the server-managed lifecycle timestamps (see
     * Maintenance::lifecycleStatus); it is never stored and never taken
     * from the payload.
     *
     * @return array{id: string, household_id: string, asset_id: ?string, title: string, description: ?string, location: string, scheduled_date: ?string, completed_at: ?string, cancelled_at: ?string, priority: string, status: string, created_at: mixed, updated_at: mixed}
     */
    private function presentMaintenance(Maintenance $maintenance): array
    {
        return [
            'id' => $maintenance->id,
            'household_id' => $maintenance->household_id,
            'asset_id' => $maintenance->asset_id,
            'title' => $maintenance->title,
            'description' => $maintenance->description,
            'location' => $maintenance->location,
            'scheduled_date' => $maintenance->scheduled_date?->format('Y-m-d'),
            'completed_at' => $maintenance->completed_at?->format('Y-m-d'),
            'cancelled_at' => $maintenance->cancelled_at?->format('Y-m-d'),
            'priority' => $maintenance->priority,
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

    private function maintenanceNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'MAINTENANCE_NOT_FOUND',
                'message' => 'Maintenance not found.',
            ],
        ], 404);
    }

    private function assetNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'ASSET_NOT_FOUND',
                'message' => 'Asset not found.',
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
