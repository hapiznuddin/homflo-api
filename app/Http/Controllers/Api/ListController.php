<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IndexListRequest;
use App\Http\Requests\Api\StoreListRequest;
use App\Http\Requests\Api\UpdateListRequest;
use App\Models\Household;
use App\Models\HouseholdList;
use App\Models\User;
use App\Services\Household\HouseholdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ListController extends Controller
{
    public function __construct(
        private readonly HouseholdService $householdService,
    ) {}

    public function index(IndexListRequest $request): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        $filters = $request->filters();

        $query = HouseholdList::query()
            ->where('household_id', $household->id)
            ->withCount([
                'items as active_items_count' => fn ($query) => $query->whereNull('completed_at'),
                'items as completed_items_count' => fn ($query) => $query->whereNotNull('completed_at'),
            ]);

        if (! $filters['include_archived']) {
            $query->active();
        }

        if ($filters['type'] !== null) {
            $query->where('type', $filters['type']);
        }

        $lists = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $lists->map(fn (HouseholdList $list) => $this->presentList($list))->all(),
        ]);
    }

    public function default(Request $request): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        $defaultId = $household->default_list_id;

        if ($defaultId === null) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DEFAULT_LIST_REQUIRED',
                    'message' => 'Household must have a default active Shopping List.',
                ],
            ], 409);
        }

        $list = HouseholdList::query()
            ->where('household_id', $household->id)
            ->whereKey($defaultId)
            ->first();

        // The pointer must resolve to an active shopping list owned by
        // this household. Anything else is masked as not found.
        if ($list === null || $list->isArchived() || $list->type !== 'shopping') {
            return $this->listNotFound();
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentList($list),
        ]);
    }

    public function show(Request $request, HouseholdList $list): JsonResponse
    {
        $resolved = $this->resolveList($request->user(), $list);

        if ($resolved === null) {
            return $this->listNotFound();
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentList($resolved),
        ]);
    }

    public function store(StoreListRequest $request): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        $validated = $request->validated();

        // All household members may create lists (product decision for
        // Lists). Membership is already proven by resolving the session
        // household; there is no separate policy gate.
        $list = DB::transaction(function () use ($validated, $household, $request): HouseholdList {
            /** @var Household $lockedHousehold */
            $lockedHousehold = Household::query()
                ->whereKey($household->id)
                ->lockForUpdate()
                ->firstOrFail();

            $list = HouseholdList::create([
                'household_id' => $lockedHousehold->id,
                'name' => trim((string) $validated['name']),
                'type' => $validated['type'],
                'created_by' => $request->user()->id,
            ]);

            // Self-heal the default pointer only when it is still empty
            // and the new list can serve as a Shopping default.
            if ($lockedHousehold->default_list_id === null && $list->type === 'shopping') {
                $lockedHousehold->default_list_id = $list->id;
                $lockedHousehold->save();
            }

            return $list;
        });

        return response()->json([
            'success' => true,
            'data' => $this->presentList($list->refresh()),
        ], 201);
    }

    public function update(UpdateListRequest $request, HouseholdList $list): JsonResponse
    {
        $resolved = $this->resolveList($request->user(), $list);

        if ($resolved === null) {
            return $this->listNotFound();
        }

        if ($resolved->isArchived()) {
            return $this->listArchived('List is archived and read-only.');
        }

        $resolved->update([
            'name' => trim((string) $request->validated()['name']),
        ]);

        $resolved->touch();

        return response()->json([
            'success' => true,
            'data' => $this->presentList($resolved->refresh()),
        ]);
    }

    public function archive(Request $request, HouseholdList $list): JsonResponse
    {
        $resolved = $this->resolveList($request->user(), $list);

        if ($resolved === null) {
            return $this->listNotFound();
        }

        // Archive is idempotent: an already archived list is returned
        // unchanged instead of surfacing LIST_ARCHIVED.
        if ($resolved->isArchived()) {
            return response()->json([
                'success' => true,
                'data' => $this->presentList($resolved),
            ]);
        }

        $result = DB::transaction(function () use ($resolved): array {
            /** @var Household $lockedHousehold */
            $lockedHousehold = Household::query()
                ->whereKey($resolved->household_id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var HouseholdList $lockedList */
            $lockedList = HouseholdList::query()
                ->whereKey($resolved->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedList->archived_at !== null) {
                return ['list' => $lockedList];
            }

            if ($lockedHousehold->default_list_id === $lockedList->id) {
                $candidate = HouseholdList::query()
                    ->where('household_id', $lockedHousehold->id)
                    ->where('type', 'shopping')
                    ->whereNull('archived_at')
                    ->where('id', '!=', $lockedList->id)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if ($candidate === null) {
                    return ['error' => 'default_required'];
                }

                $lockedHousehold->default_list_id = $candidate->id;
                $lockedHousehold->save();
            }

            $lockedList->archived_at = now();
            $lockedList->save();

            return ['list' => $lockedList];
        });

        if (($result['error'] ?? null) === 'default_required') {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DEFAULT_LIST_REQUIRED',
                    'message' => 'Household must have a default active Shopping List.',
                ],
            ], 409);
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentList($result['list']->refresh()),
        ]);
    }

    public function restore(Request $request, HouseholdList $list): JsonResponse
    {
        $resolved = $this->resolveList($request->user(), $list);

        if ($resolved === null) {
            return $this->listNotFound();
        }

        // Restore is idempotent and never touches the default pointer.
        if ($resolved->isArchived()) {
            $resolved->archived_at = null;
            $resolved->save();
            $resolved->touch();
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentList($resolved->refresh()),
        ]);
    }

    private function resolveHousehold(?User $user): ?Household
    {
        if ($user === null) {
            return null;
        }

        $context = $this->householdService->resolveUserHouseholdContext($user);

        if ($context['household'] === null) {
            return null;
        }

        return Household::query()->find($context['household']['id']);
    }

    /**
     * Resolve a list against the authenticated household. Cross-household
     * access is masked as 404, never 403.
     */
    private function resolveList(?User $user, HouseholdList $list): ?HouseholdList
    {
        $household = $this->resolveHousehold($user);

        if ($household === null || $list->household_id !== $household->id) {
            return null;
        }

        return $list;
    }

    private function presentList(HouseholdList $list): array
    {
        $activeCount = isset($list->active_items_count)
            ? (int) $list->active_items_count
            : $list->activeItemCount();
        $completedCount = isset($list->completed_items_count)
            ? (int) $list->completed_items_count
            : $list->completedItemCount();

        return [
            'id' => $list->id,
            'household_id' => $list->household_id,
            'name' => $list->name,
            'type' => $list->type,
            'is_default' => $list->isDefaultForHousehold(),
            'archived_at' => $list->archived_at,
            'counts' => [
                'active' => $activeCount,
                'completed' => $completedCount,
            ],
            'created_by' => $list->creator ? [
                'id' => $list->creator->id,
                'name' => $list->creator->name,
            ] : null,
            'created_at' => $list->created_at,
            'updated_at' => $list->updated_at,
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

    private function listNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'LIST_NOT_FOUND',
                'message' => 'List not found.',
            ],
        ], 404);
    }

    private function listArchived(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'LIST_ARCHIVED',
                'message' => $message,
            ],
        ], 409);
    }
}
