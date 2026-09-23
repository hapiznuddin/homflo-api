<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AlreadyHouseholdMemberException;
use App\Exceptions\InvitationAlreadyAcceptedException;
use App\Exceptions\InvitationExpiredException;
use App\Exceptions\InvitationNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AcceptHouseholdInvitationRequest;
use App\Models\HouseholdInvitation;
use App\Services\Household\HouseholdInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvitationController extends Controller
{
    public function __construct(
        private readonly HouseholdInvitationService $invitationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->invitationService->listForUser($request->user()),
        ]);
    }

    public function accept(AcceptHouseholdInvitationRequest $request, HouseholdInvitation $invitation): JsonResponse
    {
        try {
            $member = $this->invitationService->accept(
                $request->user(),
                $invitation->id,
                $request->validated()['token'],
            );
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
        } catch (InvitationExpiredException $exception) {
            return $this->unprocessable('INVITATION_EXPIRED', $exception->getMessage());
        } catch (AlreadyHouseholdMemberException $exception) {
            return $this->unprocessable('ALREADY_HOUSEHOLD_MEMBER', $exception->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => $member,
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
