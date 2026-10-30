<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AlreadyHouseholdMemberException;
use App\Exceptions\InvitationAlreadyAcceptedException;
use App\Exceptions\InvitationAlreadyPendingException;
use App\Exceptions\InvitationNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreHouseholdInvitationRequest;
use App\Models\Household;
use App\Services\Household\HouseholdInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class HouseholdInvitationController extends Controller
{
    private const STATUS_FILTERS = ['pending', 'accepted', 'revoked', 'expired', 'all'];

    public function __construct(
        private readonly HouseholdInvitationService $invitationService,
    ) {}

    public function index(Request $request, Household $household): JsonResponse
    {
        if (! Gate::allows('viewMembers', $household)) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('manageMembers', $household)) {
            return $this->forbidden();
        }

        $status = $request->query('status', 'pending');

        $validator = Validator::make(
            ['status' => $status],
            ['status' => ['required', 'string', 'in:'.implode(',', self::STATUS_FILTERS)]],
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'The selected status is invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->invitationService->listForHousehold($household, (string) $status),
        ]);
    }

    public function store(StoreHouseholdInvitationRequest $request, Household $household): JsonResponse
    {
        if (! Gate::allows('viewMembers', $household)) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('manageMembers', $household)) {
            return $this->forbidden();
        }

        try {
            $result = $this->invitationService->invite(
                $household,
                $request->user(),
                $request->validated()['email'],
            );
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

    public function revoke(Request $request, Household $household, string $invitationId): JsonResponse
    {
        if (! Gate::allows('viewMembers', $household)) {
            return $this->householdNotFound();
        }

        if (! Gate::allows('manageMembers', $household)) {
            return $this->forbidden();
        }

        try {
            $result = $this->invitationService->revoke($request->user(), $household, $invitationId);
        } catch (InvitationNotFoundException $exception) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVITATION_NOT_FOUND',
                    'message' => $exception->getMessage(),
                ],
            ], 404);
        } catch (InvitationAlreadyAcceptedException $exception) {
            return $this->unprocessable('INVITATION_ALREADY_ACCEPTED', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
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
