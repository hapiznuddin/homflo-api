<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DashboardPeriodRequest;
use App\Services\Dashboard\DashboardService;
use App\Services\Household\HouseholdService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly HouseholdService $householdService,
    ) {}

    public function __invoke(DashboardPeriodRequest $request): JsonResponse
    {
        $context = $this->householdService->resolveUserHouseholdContext($request->user());

        if ($context['household'] === null) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'HOUSEHOLD_NOT_FOUND',
                    'message' => 'Household not found.',
                ],
            ], 404);
        }

        $period = $request->period();

        return response()->json([
            'success' => true,
            'data' => $this->dashboardService->forHouseholdInPeriod(
                $context['household'],
                $period['year'],
                $period['month']
            ),
        ]);
    }
}
