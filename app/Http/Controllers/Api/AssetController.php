<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAssetRequest;
use App\Http\Requests\Api\UpdateAssetRequest;
use App\Models\Asset;
use App\Models\Household;
use App\Repositories\Contracts\AssetRepositoryInterface;
use App\Services\Asset\AssetService;
use App\Services\Household\HouseholdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AssetController extends Controller
{
    public function __construct(
        private readonly AssetService $assetService,
        private readonly HouseholdService $householdService,
        private readonly AssetRepositoryInterface $assets,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('viewAny', [Asset::class, $household])) {
            return $this->householdNotFound();
        }

        $assets = $this->assetService->listForHousehold($household);

        return response()->json([
            'success' => true,
            'data' => $assets->map(fn (Asset $asset) => $this->presentAsset($asset))->all(),
        ]);
    }

    public function store(StoreAssetRequest $request): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('create', [Asset::class, $household])) {
            return $this->householdNotFound();
        }

        $asset = $this->assetService->record($household, $request->validated());

        return response()->json([
            'success' => true,
            'data' => $this->presentAsset($asset),
        ], 201);
    }

    public function show(Asset $asset): JsonResponse
    {
        $resolved = $this->resolveAsset($asset);

        if ($resolved === null) {
            return $this->assetNotFound();
        }

        if (! Gate::allows('view', $resolved)) {
            return $this->assetNotFound();
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentAsset($resolved),
        ]);
    }

    public function update(UpdateAssetRequest $request, Asset $asset): JsonResponse
    {
        $resolved = $this->resolveAsset($asset);

        if ($resolved === null) {
            return $this->assetNotFound();
        }

        if (! Gate::allows('update', $resolved)) {
            return $this->assetNotFound();
        }

        $updated = $this->assetService->change($resolved, $request->validated());

        return response()->json([
            'success' => true,
            'data' => $this->presentAsset($updated),
        ]);
    }

    public function destroy(Asset $asset): JsonResponse
    {
        $resolved = $this->resolveAsset($asset);

        if ($resolved === null) {
            return $this->assetNotFound();
        }

        if (! Gate::allows('delete', $resolved)) {
            return $this->assetNotFound();
        }

        $this->assetService->remove($resolved);

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

    private function resolveAsset(Asset $asset): ?Asset
    {
        $context = $this->householdService->resolveUserHouseholdContext(request()->user());

        if ($context['household'] === null) {
            return null;
        }

        if ($asset->household_id !== $context['household']['id']) {
            return null;
        }

        $household = Household::query()->find($context['household']['id']);

        if ($household === null) {
            return null;
        }

        return $this->assets->findByIdForHousehold($household, $asset->id);
    }

    /**
     * @return array{id: string, household_id: string, name: string, description: ?string, location: ?string, acquired_on: ?string, acquisition_cost: ?string, created_at: mixed, updated_at: mixed}
     */
    private function presentAsset(Asset $asset): array
    {
        return [
            'id' => $asset->id,
            'household_id' => $asset->household_id,
            'name' => $asset->name,
            'description' => $asset->description,
            'location' => $asset->location,
            'acquired_on' => $asset->acquired_on?->format('Y-m-d'),
            'acquisition_cost' => $asset->acquisition_cost === null ? null : (string) $asset->acquisition_cost,
            'created_at' => $asset->created_at,
            'updated_at' => $asset->updated_at,
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
}
