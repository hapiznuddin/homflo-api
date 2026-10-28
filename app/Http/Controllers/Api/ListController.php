<?php

namespace App\Http\Controllers\Api;

use App\Models\HouseholdList;
use App\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreListRequest;
use App\Http\Requests\Api\UpdateListRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        $lists = HouseholdList::where('household_id', $household->id)
            ->active()
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

        $list = HouseholdList::find($defaultId);

        if ($list === null || $list->isArchived()) {
            return $this->listNotFound();
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentList($list),
        ]);
    }

    public function show(HouseholdList $list): JsonResponse
    {
        $household = $list->household;

        // Cross-household access masked as 404
        if ($household === null || $list->household_id !== $household->id) {
            return $this->listNotFound();
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentList($list),
        ]);
    }

    public function store(StoreListRequest $request): JsonResponse
    {
        $household = $this->resolveHousehold($request->user());

        if ($household === null) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('create', [HouseholdList::class, $household])) {
            return $this->householdNotFound();
        }

        // Create default list within transaction + lock household
        $list = HouseholdList::transaction(function () use ($request, $household) {
            $list = new HouseholdList([
                'household_id' => $household->id,
                'name' => $request->validated()['name'] ?? HouseholdList::DEFAULT_SHOPPING_NAME,
                'type' => 'shopping',
                'created_by' => $request->user()->id,
            ]);

            $list->save();

            // Set default_list_id pointer atomically
            $household->default_list_id = $list->id;
            $household->save();

            return $list;
        });

        return response()->json([
            'success' => true,
            'data' => $this->presentList($list),
        ], 201);
    }

    public function update(UpdateListRequest $request, HouseholdList $list): JsonResponse
    {
        $household = $list->household;

        // Cross-household access masked as 404
        if ($household === null || $list->household_id !== $household->id) {
            return $this->listNotFound();
        }

        // Archive-only: name can be updated for active lists; archived lists are read-only
        if ($list->isArchived()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'LIST_ARCHIVED',
                    'message' => 'List is archived and read-only.',
                ],
            ], 409);
        }

        $list->update([
            'name' => $request->validated()['name'],
        ]);

        $list->touch();

        return response()->json([
            'success' => true,
            'data' => $this->presentList($list),
        ]);
    }

    public function archive(HouseholdList $list): JsonResponse
    {
        $household = $list->household;

        // Cross-household access masked as 404
        if ($household === null || $list->household_id !== $household->id) {
            return $this->listNotFound();
        }

        // Opsi rekomendasi: lock baris household, cek kandidat default
        if ($list->isDefaultForHousehold()) {
            // Cari kandidat aktif type=shopping selain target
            $candidate = HouseholdList::where('household_id', $household->id)
                ->where('type', 'shopping')
                ->where('archived_at', null)
                ->where('id', '!=', $list->id)
                ->orderBy('created_at', 'ASC')
                ->orderBy('id', 'ASC')
                ->first();

            if ($candidate !== null) {
                // Pindahkan pointer dan arsipkan target
                HouseholdList::transaction(function () use ($household, $list, $candidate) {
                    $household->default_list_id = $candidate->id;
                    $household->save();

                    $list->archived_at = now();
                    $list->save();
                });

                return response()->json([
                    'success' => true,
                    'data' => $this->presentList($list),
                ]);
            }
        }

        // Jika kandidat tidak ada: tolak dengan 409
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'DEFAULT_LIST_REQUIRED',
                'message' => 'Household must have a default active Shopping List.',
            ],
        ], 409);
    }

    public function restore(HouseholdList $list): JsonResponse
    {
        $household = $list->household;

        // Cross-household access masked as 404
        if ($household === null || $list->household_id !== $household->id) {
            return $this->listNotFound();
        }

        // List restore: hanya mengosongkan archived_at
        $list->archived_at = null;
        $list->save();

        $list->touch();

        return response()->json([
            'success' => true,
            'data' => $this->presentList($list),
        ]);
    }

    private function resolveHousehold(User $user): ?\App\Models\Household
    {
        $context = $this->app['household.service']->resolveUserHouseholdContext($user);

        if ($context['household'] === null) {
            return null;
        }

        return \App\Models\Household::query()->find($context['household']['id']);
    }

    private function presentList(HouseholdList $list): array
    {
        return [
            'id' => $list->id,
            'household_id' => $list->household_id,
            'name' => $list->name,
            'type' => $list->type,
            'is_default' => $list->isDefaultForHousehold(),
            'archived_at' => $list->archived_at,
            'counts' => [
                'active' => $list->activeItemCount(),
                'completed' => $list->completedItemCount(),
            ],
            'created_by' => $list->creator ? [
                'id' => $list->creator->id,
                'name' => $list->creator->name,
           ] : null,
            'created_at' => $list->created_at,
            'updated_at' => $list->updated_at,
        ];
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
}