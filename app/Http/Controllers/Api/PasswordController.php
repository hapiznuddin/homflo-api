<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidCurrentPasswordException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ChangePasswordRequest;
use App\Services\Auth\PasswordChangeService;
use Illuminate\Http\JsonResponse;

class PasswordController extends Controller
{
    public function __construct(
        private readonly PasswordChangeService $passwordChangeService,
    ) {}

    public function change(ChangePasswordRequest $request): JsonResponse
    {
        try {
            $this->passwordChangeService->changePassword(
                $request->user(),
                $request->validated()['current_password'],
                $request->validated()['password'],
            );
        } catch (InvalidCurrentPasswordException $exception) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_CURRENT_PASSWORD',
                    'message' => $exception->getMessage(),
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'message' => 'Password changed successfully.',
            ],
        ]);
    }
}
