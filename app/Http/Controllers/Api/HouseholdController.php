<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\HouseholdAlreadyExistsException;
use App\Exceptions\HouseholdNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreHouseholdRequest;
use App\Http\Requests\Api\UpdateHouseholdRequest;
use App\Models\Household;
use App\Services\Household\HouseholdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class HouseholdController extends Controller
{
    public function __construct(
        private readonly HouseholdService $householdService,
    ) {}

    public function store(StoreHouseholdRequest $request): JsonResponse
    {
        try {
            $householdContext = $this->householdService->createHouseholdForUser(
                $request->user(),
                $request->validated()['name'],
            );
        } catch (HouseholdAlreadyExistsException $exception) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'HOUSEHOLD_ALREADY_EXISTS',
                    'message' => $exception->getMessage(),
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $householdContext,
        ], 201);
    }

    public function update(UpdateHouseholdRequest $request, Household $household): JsonResponse
    {
        // Cross-household access is masked as 404, members receive 403.
        if (! Gate::allows('viewMembers', $household)) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('update', $household)) {
            return $this->forbidden();
        }

        $context = $this->householdService->renameHousehold(
            $request->user(),
            $household,
            $request->validated()['name'],
        );

        return response()->json([
            'success' => true,
            'data' => $context,
        ]);
    }

    public function activate(Request $request, Household $household): JsonResponse
    {
        if (! Gate::allows('viewMembers', $household)) {
            return $this->householdNotFound();
        }

        try {
            $context = $this->householdService->activateHouseholdForUser($request->user(), $household);
        } catch (HouseholdNotFoundException) {
            return $this->householdNotFound();
        }

        return response()->json([
            'success' => true,
            'data' => $context,
        ]);
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

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'FORBIDDEN',
                'message' => 'This action is unauthorized.',
            ],
        ], 403);
    }
}
