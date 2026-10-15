<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BudgetAlreadyExistsException;
use App\Exceptions\CategoryNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IndexBudgetRequest;
use App\Http\Requests\Api\StoreBudgetRequest;
use App\Http\Requests\Api\UpdateBudgetRequest;
use App\Models\Budget;
use App\Models\Household;
use App\Repositories\Contracts\BudgetRepositoryInterface;
use App\Services\Finance\BudgetService;
use App\Services\Household\HouseholdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class BudgetController extends Controller
{
    public function __construct(
        private readonly BudgetService $budgetService,
        private readonly HouseholdService $householdService,
        private readonly BudgetRepositoryInterface $budgets,
    ) {}

    public function index(IndexBudgetRequest $request): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('viewAny', [Budget::class, $household])) {
            return $this->householdNotFound();
        }

        $result = $this->budgetService->listWithSpending($household, $request->filters());

        $response = [
            'success' => true,
            'data' => $result['items']->map(fn (Budget $budget) => $this->presentBudget(
                $budget,
                $this->budgetService->deriveFor($household, $budget, $result['today']),
            ))->all(),
        ];

        if ($result['householdTotal'] !== null && $result['period'] !== null) {
            $response['meta'] = [
                'period' => $result['period'],
                'household' => ['actual_spending' => $result['householdTotal']],
            ];
        }

        return response()->json($response);
    }

    public function store(StoreBudgetRequest $request): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('create', [Budget::class, $household])) {
            return $this->householdNotFound();
        }

        try {
            $budget = $this->budgetService->record($household, $request->validated());
        } catch (CategoryNotFoundException) {
            return $this->categoryNotFound();
        } catch (BudgetAlreadyExistsException $exception) {
            return $this->unprocessable('BUDGET_ALREADY_EXISTS', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentBudget($budget, $this->budgetService->deriveFor($household, $budget)),
        ], 201);
    }

    public function show(Budget $budget): JsonResponse
    {
        $resolved = $this->resolveBudget($budget);

        if ($resolved === null) {
            return $this->budgetNotFound();
        }

        if (! Gate::allows('view', $resolved)) {
            return $this->budgetNotFound();
        }

        $household = $resolved->household;

        if ($household === null) {
            return $this->budgetNotFound();
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentBudget($resolved, $this->budgetService->deriveFor($household, $resolved)),
        ]);
    }

    public function update(UpdateBudgetRequest $request, Budget $budget): JsonResponse
    {
        $resolved = $this->resolveBudget($budget);

        if ($resolved === null) {
            return $this->budgetNotFound();
        }

        if (! Gate::allows('update', $resolved)) {
            return $this->budgetNotFound();
        }

        $household = $resolved->household;

        if ($household === null) {
            return $this->budgetNotFound();
        }

        try {
            $updated = $this->budgetService->change($household, $resolved, $request->validated());
        } catch (CategoryNotFoundException) {
            return $this->categoryNotFound();
        } catch (BudgetAlreadyExistsException $exception) {
            return $this->unprocessable('BUDGET_ALREADY_EXISTS', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentBudget($updated, $this->budgetService->deriveFor($household, $updated)),
        ]);
    }

    public function destroy(Budget $budget): JsonResponse
    {
        $resolved = $this->resolveBudget($budget);

        if ($resolved === null) {
            return $this->budgetNotFound();
        }

        if (! Gate::allows('delete', $resolved)) {
            return $this->budgetNotFound();
        }

        $this->budgetService->remove($resolved);

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

    private function resolveBudget(Budget $budget): ?Budget
    {
        $context = $this->householdService->resolveUserHouseholdContext(request()->user());

        if ($context['household'] === null) {
            return null;
        }

        if ($budget->household_id !== $context['household']['id']) {
            return null;
        }

        $household = Household::query()->find($context['household']['id']);

        if ($household === null) {
            return null;
        }

        return $this->budgets->findByIdForHousehold($household, $budget->id);
    }

    /**
     * @param  array{actual_spending: string, remaining: string, usage_percentage: ?float, usage_status: string}  $derived
     * @return array{id: string, household_id: string, category: ?array{id: string, name: string, icon: ?string}, year: int, month: int, amount: string, actual_spending: string, remaining: string, usage_percentage: ?float, usage_status: string, created_at: mixed, updated_at: mixed}
     */
    private function presentBudget(Budget $budget, array $derived): array
    {
        $budget->loadMissing('category');

        return [
            'id' => $budget->id,
            'household_id' => $budget->household_id,
            'category' => $budget->category === null ? null : [
                'id' => $budget->category->id,
                'name' => $budget->category->name,
                'icon' => $budget->category->icon,
            ],
            'year' => $budget->year,
            'month' => $budget->month,
            'amount' => (string) $budget->amount,
            'actual_spending' => $derived['actual_spending'],
            'remaining' => $derived['remaining'],
            'usage_percentage' => $derived['usage_percentage'],
            'usage_status' => $derived['usage_status'],
            'created_at' => $budget->created_at,
            'updated_at' => $budget->updated_at,
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

    private function budgetNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'BUDGET_NOT_FOUND',
                'message' => 'Budget not found.',
            ],
        ], 404);
    }

    private function categoryNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'CATEGORY_NOT_FOUND',
                'message' => 'Category not found.',
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
