<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\HouseholdLastOwnerException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateHouseholdMemberRequest;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Repositories\Contracts\HouseholdMemberRepositoryInterface;
use App\Services\Household\HouseholdMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class HouseholdMemberController extends Controller
{
    public function __construct(
        private readonly HouseholdMembershipService $membershipService,
        private readonly HouseholdMemberRepositoryInterface $householdMembers,
    ) {}

    public function index(Household $household): JsonResponse
    {
        if (! Gate::allows('viewMembers', $household)) {
            return $this->householdNotFound();
        }

        $members = $this->householdMembers->listForHousehold($household);

        return response()->json([
            'success' => true,
            'data' => $members->map(fn (HouseholdMember $membership) => $this->presentMember($membership))->all(),
        ]);
    }

    public function update(UpdateHouseholdMemberRequest $request, Household $household, HouseholdMember $member): JsonResponse
    {
        $membership = $this->resolveMembership($household, $member);

        if ($membership === null) {
            return $this->membershipNotFound();
        }

        if (! Gate::allows('viewMembers', $household)) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('manageMembers', $household)) {
            return $this->forbidden();
        }

        try {
            $updated = $this->membershipService->changeRole(
                $household,
                $membership,
                $request->validated()['role'],
            );
        } catch (HouseholdLastOwnerException $exception) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'HOUSEHOLD_LAST_OWNER',
                    'message' => $exception->getMessage(),
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentMember($updated),
        ]);
    }

    public function destroy(Household $household, HouseholdMember $member): JsonResponse
    {
        $membership = $this->resolveMembership($household, $member);

        if ($membership === null) {
            return $this->membershipNotFound();
        }

        if (! Gate::allows('viewMembers', $household)) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('manageMembers', $household)) {
            return $this->forbidden();
        }

        try {
            $this->membershipService->removeMember($household, $membership);
        } catch (HouseholdLastOwnerException $exception) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'HOUSEHOLD_LAST_OWNER',
                    'message' => $exception->getMessage(),
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => ['id' => $membership->id],
        ]);
    }

    private function resolveMembership(Household $household, HouseholdMember $member): ?HouseholdMember
    {
        if ($member->household_id !== $household->id) {
            return null;
        }

        return $this->householdMembers->findByIdForHousehold($household, $member->id);
    }

    /**
     * @return array{id: string, user_id: string, username: string, name: string, email: string, role: string, created_at: mixed, updated_at: mixed}
     */
    private function presentMember(HouseholdMember $membership): array
    {
        $membership->loadMissing('user');

        return [
            'id' => $membership->id,
            'user_id' => $membership->user_id,
            'username' => $membership->user->username,
            'name' => $membership->user->name,
            'email' => $membership->user->email,
            'role' => $membership->role,
            'created_at' => $membership->created_at,
            'updated_at' => $membership->updated_at,
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

    private function membershipNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'MEMBERSHIP_NOT_FOUND',
                'message' => 'Membership not found.',
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
