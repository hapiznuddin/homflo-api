<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ObligationTypeAlreadyExistsException;
use App\Exceptions\ObligationTypeImmutableException;
use App\Exceptions\ObligationTypeInUseException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreObligationTypeRequest;
use App\Http\Requests\Api\UpdateObligationTypeRequest;
use App\Models\Household;
use App\Models\ObligationType;
use App\Repositories\Contracts\ObligationTypeRepositoryInterface;
use App\Services\Household\HouseholdService;
use App\Services\Vehicle\ObligationTypeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ObligationTypeController extends Controller
{
    public function __construct(
        private readonly ObligationTypeService $typeService,
        private readonly HouseholdService $householdService,
        private readonly ObligationTypeRepositoryInterface $types,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('viewAny', [ObligationType::class, $household])) {
            return $this->householdNotFound();
        }

        $types = $this->typeService->listForHousehold($household);

        return response()->json([
            'success' => true,
            'data' => $types->map(fn (ObligationType $type) => $this->presentType($type))->all(),
        ]);
    }

    public function store(StoreObligationTypeRequest $request): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('create', [ObligationType::class, $household])) {
            return $this->householdNotFound();
        }

        try {
            $type = $this->typeService->record($household, $request->validated());
        } catch (ObligationTypeAlreadyExistsException $exception) {
            return $this->unprocessable('OBLIGATION_TYPE_ALREADY_EXISTS', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentType($type),
        ], 201);
    }

    public function update(UpdateObligationTypeRequest $request, ObligationType $obligationType): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        if ($obligationType->is_system) {
            return $this->immutable();
        }

        $resolved = $this->types->findCustomForHouseholdById($household, $obligationType->id);

        if ($resolved === null) {
            return $this->typeNotFound();
        }

        if (! Gate::allows('update', $resolved)) {
            return $this->typeNotFound();
        }

        try {
            $updated = $this->typeService->change($household, $resolved, $request->validated());
        } catch (ObligationTypeImmutableException) {
            return $this->immutable();
        } catch (ObligationTypeAlreadyExistsException $exception) {
            return $this->unprocessable('OBLIGATION_TYPE_ALREADY_EXISTS', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentType($updated),
        ]);
    }

    public function destroy(ObligationType $obligationType): JsonResponse
    {
        $household = $this->resolveHousehold(request()->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        if ($obligationType->is_system) {
            return $this->immutable();
        }

        $resolved = $this->types->findCustomForHouseholdById($household, $obligationType->id);

        if ($resolved === null) {
            return $this->typeNotFound();
        }

        if (! Gate::allows('delete', $resolved)) {
            return $this->typeNotFound();
        }

        try {
            $this->typeService->remove($resolved);
        } catch (ObligationTypeImmutableException) {
            return $this->immutable();
        } catch (ObligationTypeInUseException $exception) {
            return $this->unprocessable('OBLIGATION_TYPE_IN_USE', $exception->getMessage());
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

    /**
     * @return array{id: string, household_id: ?string, code: ?string, name: string, is_system: bool, created_at: mixed, updated_at: mixed}
     */
    private function presentType(ObligationType $type): array
    {
        return [
            'id' => $type->id,
            'household_id' => $type->household_id,
            'code' => $type->code,
            'name' => $type->name,
            'is_system' => (bool) $type->is_system,
            'created_at' => $type->created_at,
            'updated_at' => $type->updated_at,
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

    private function typeNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'OBLIGATION_TYPE_NOT_FOUND',
                'message' => 'Obligation type not found.',
            ],
        ], 404);
    }

    private function immutable(): JsonResponse
    {
        return $this->unprocessable(
            'OBLIGATION_TYPE_IMMUTABLE',
            'Built-in obligation types cannot be changed or deleted.',
        );
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
