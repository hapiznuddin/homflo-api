<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AlreadyHouseholdMemberException;
use App\Exceptions\InvitationAlreadyPendingException;
use App\Exceptions\InvitedUserNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreHouseholdInvitationRequest;
use App\Models\Household;
use App\Services\Household\HouseholdInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class HouseholdInvitationController extends Controller
{
    public function __construct(
        private readonly HouseholdInvitationService $invitationService,
    ) {}

    public function store(StoreHouseholdInvitationRequest $request, Household $household): JsonResponse
    {
        if (! Gate::allows('viewMembers', $household)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'HOUSEHOLD_NOT_FOUND',
                    'message' => 'Household not found.',
                ],
            ], 404);
        }

        if (! Gate::allows('manageMembers', $household)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'This action is unauthorized.',
                ],
            ], 403);
        }

        try {
            $result = $this->invitationService->invite(
                $household,
                $request->user(),
                $request->validated()['email'],
            );
        } catch (InvitedUserNotFoundException $exception) {
            return $this->unprocessable('INVITED_USER_NOT_FOUND', $exception->getMessage());
        } catch (AlreadyHouseholdMemberException $exception) {
            return $this->unprocessable('ALREADY_HOUSEHOLD_MEMBER', $exception->getMessage());
        } catch (InvitationAlreadyPendingException $exception) {
            return $this->unprocessable('INVITATION_ALREADY_PENDING', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ], 201);
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
