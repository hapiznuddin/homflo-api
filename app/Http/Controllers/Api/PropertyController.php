<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StorePropertyRequest;
use App\Http\Requests\Api\UpdatePropertyRequest;
use App\Models\Household;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PropertyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $household = app()->make('App\Services\Household\HouseholdService')
            ->resolveUserHouseholdContext($request->user());

        if ($household['household'] === null) {
            return $this->householdNotFound();
        }

        $householdModel = Household::query()
            ->find($household['household']['id']);

        if ($householdModel === null) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('viewAny', [Property::class, $householdModel])) {
            return $this->householdNotFound();
        }

        $includeArchived = $request->query('include_archived', false);

        $query = Property::query()
            ->where('household_id', $householdModel->id);

        if (! $includeArchived) {
            $query->whereNull('archived_at');
        }

        $properties = $query->orderBy('name', 'ASC')->get();

        return response()->json([
            'success' => true,
            'data' => $properties->map(fn (Property $property) => $this->presentProperty($property))->all(),
            'include_archived' => $includeArchived,
        ]);
    }

    public function store(StorePropertyRequest $request): JsonResponse
    {
        $household = app()->make('App\Services\Household\HouseholdService')
            ->resolveUserHouseholdContext($request->user());

        if ($household['household'] === null) {
            return $this->householdNotFound();
        }

        $householdModel = Household::query()
            ->find($household['household']['id']);

        if ($householdModel === null) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('create', [Property::class, $householdModel])) {
            return $this->householdNotFound();
        }

        $property = Property::create([
            'household_id' => $householdModel->id,
            'name' => $request->validated('name'),
            'type' => $request->validated('type'),
            'address' => $request->validated('address'),
            'description' => $request->validated('description'),
            'ownership_status' => $request->validated('ownership_status'),
            'notes' => $request->validated('notes'),
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->presentProperty($property),
        ], 201);
    }

    public function show(Property $property): JsonResponse
    {
        $resolved = $this->resolveProperty($property);

        if ($resolved === null) {
            return $this->propertyNotFound();
        }

        if (! Gate::allows('view', $resolved)) {
            return $this->propertyNotFound();
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentProperty($resolved),
        ]);
    }

    public function update(UpdatePropertyRequest $request, Property $property): JsonResponse
    {
        $resolved = $this->resolveProperty($property);

        if ($resolved === null) {
            return $this->propertyNotFound();
        }

        if (! Gate::allows('update', $resolved)) {
            return $this->propertyNotFound();
        }

        // Check if property is archived - reject with 409
        if ($resolved->archived_at !== null) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'PROPERTY_ARCHIVED',
                    'message' => 'Property is archived and cannot be edited. Use restore endpoint to reactivate.',
                ],
            ], 409);
        }

        $updated = Property::where('id', $resolved->id)
            ->update($request->validated());

        $fresh = Property::find($resolved->id);

        return response()->json([
            'success' => true,
            'data' => $this->presentProperty($fresh),
        ]);
    }

    public function archive(Property $property): JsonResponse
    {
        $resolved = $this->resolveProperty($property);

        if ($resolved === null) {
            return $this->propertyNotFound();
        }

        if (! Gate::allows('update', $resolved)) {
            return $this->propertyNotFound();
        }

        // Check if already archived
        if ($resolved->archived_at !== null) {
            return response()->json([
                'success' => true,
                'data' => $this->presentProperty($resolved),
                'message' => 'Property is already archived.',
            ], 200);
        }

        $archived = Property::where('id', $resolved->id)
            ->update(['archived_at' => now()]);

        $fresh = Property::find($resolved->id);

        return response()->json([
            'success' => true,
            'data' => $this->presentProperty($fresh),
        ]);
    }

    public function restore(Property $property): JsonResponse
    {
        $resolved = $this->resolveProperty($property);

        if ($resolved === null) {
            return $this->propertyNotFound();
        }

        if (! Gate::allows('update', $resolved)) {
            return $this->propertyNotFound();
        }

        // Check if already active
        if ($resolved->archived_at === null) {
            return response()->json([
                'success' => true,
                'data' => $this->presentProperty($resolved),
                'message' => 'Property is already active.',
            ], 200);
        }

        $restored = Property::where('id', $resolved->id)
            ->update(['archived_at' => null]);

        $fresh = Property::find($resolved->id);

        return response()->json([
            'success' => true,
            'data' => $this->presentProperty($fresh),
        ]);
    }

    private function resolveProperty(Property $property): ?Property
    {
        $context = app()->make('App\Services\Household\HouseholdService')
            ->resolveUserHouseholdContext(request()->user());

        if ($context['household'] === null) {
            return null;
        }

        if ($property->household_id !== $context['household']['id']) {
            return null;
        }

        return Property::query()
            ->where('household_id', $context['household']['id'])
            ->where('id', $property->id)
            ->first();
    }

    private function presentProperty(Property $property): array
    {
        return [
            'id' => $property->id,
            'household_id' => $property->household_id,
            'name' => $property->name,
            'type' => $property->type,
            'address' => $property->address,
            'description' => $property->description,
            'ownership_status' => $property->ownership_status,
            'notes' => $property->notes,
            'archived_at' => $property->archived_at,
            'created_at' => $property->created_at,
            'updated_at' => $property->updated_at,
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

    private function propertyNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'PROPERTY_NOT_FOUND',
                'message' => 'Property not found.',
            ],
        ], 404);
    }
}
