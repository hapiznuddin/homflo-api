<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Household\HouseholdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __construct(
        private readonly HouseholdService $householdService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $householdContext = $this->householdService->resolveUserHouseholdContext($user);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'household' => $householdContext['household'],
                'onboarding' => $householdContext['onboarding'],
            ],
        ]);
    }
}
