<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CategoryAlreadyExistsException;
use App\Exceptions\CategoryInUseException;
use App\Exceptions\CategoryTypeMismatchException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreCategoryRequest;
use App\Http\Requests\Api\UpdateCategoryRequest;
use App\Models\Category;
use App\Models\Household;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use App\Services\Finance\CategoryService;
use App\Services\Household\HouseholdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryService $categoryService,
        private readonly HouseholdService $householdService,
        private readonly CategoryRepositoryInterface $categories,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('viewAny', [Category::class, $household])) {
            return $this->householdNotFound();
        }

        $categories = $this->categoryService->listForHousehold($household);

        return response()->json([
            'success' => true,
            'data' => $categories->map(fn (Category $category) => $this->presentCategory($category))->all(),
        ]);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('create', [Category::class, $household])) {
            return $this->householdNotFound();
        }

        try {
            $category = $this->categoryService->record($household, $request->validated());
        } catch (CategoryAlreadyExistsException $exception) {
            return $this->unprocessable('CATEGORY_ALREADY_EXISTS', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentCategory($category),
        ], 201);
    }

    public function show(Category $category): JsonResponse
    {
        $resolved = $this->resolveCategory($category);

        if ($resolved === null) {
            return $this->categoryNotFound();
        }

        if (! Gate::allows('view', $resolved)) {
            return $this->categoryNotFound();
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentCategory($resolved),
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $resolved = $this->resolveCategory($category);

        if ($resolved === null) {
            return $this->categoryNotFound();
        }

        if (! Gate::allows('update', $resolved)) {
            return $this->categoryNotFound();
        }

        $household = $resolved->household;

        try {
            $updated = $this->categoryService->change($household, $resolved, $request->validated());
        } catch (CategoryAlreadyExistsException $exception) {
            return $this->unprocessable('CATEGORY_ALREADY_EXISTS', $exception->getMessage());
        } catch (CategoryTypeMismatchException $exception) {
            return $this->unprocessable('CATEGORY_TYPE_MISMATCH', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentCategory($updated),
        ]);
    }

    public function destroy(Category $category): JsonResponse
    {
        $resolved = $this->resolveCategory($category);

        if ($resolved === null) {
            return $this->categoryNotFound();
        }

        if (! Gate::allows('delete', $resolved)) {
            return $this->categoryNotFound();
        }

        try {
            $this->categoryService->remove($resolved);
        } catch (CategoryInUseException $exception) {
            return $this->unprocessable('CATEGORY_IN_USE', $exception->getMessage());
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

    private function resolveCategory(Category $category): ?Category
    {
        $context = $this->householdService->resolveUserHouseholdContext(request()->user());

        if ($context['household'] === null) {
            return null;
        }

        if ($category->household_id !== $context['household']['id']) {
            return null;
        }

        $household = Household::query()->find($context['household']['id']);

        if ($household === null) {
            return null;
        }

        return $this->categories->findByIdForHousehold($household, $category->id);
    }

    /**
     * @return array{id: string, household_id: string, name: string, icon: ?string, created_at: mixed, updated_at: mixed}
     */
    private function presentCategory(Category $category): array
    {
        return [
            'id' => $category->id,
            'household_id' => $category->household_id,
            'name' => $category->name,
            'icon' => $category->icon,
            'type' => $category->type,
            'created_at' => $category->created_at,
            'updated_at' => $category->updated_at,
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
