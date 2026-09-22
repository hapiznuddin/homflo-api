<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\HouseholdAlreadyExistsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreHouseholdRequest;
use App\Services\Household\HouseholdService;
use Illuminate\Http\JsonResponse;

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
}
