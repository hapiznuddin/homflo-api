<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidPasswordResetTokenException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ForgotPasswordRequest;
use App\Http\Requests\Api\ResetPasswordRequest;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\JsonResponse;

class PasswordResetController extends Controller
{
    public function __construct(
        private readonly PasswordResetService $passwordResetService,
    ) {}

    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwordResetService->requestResetLink($request->validated()['email']);

        return response()->json([
            'success' => true,
            'data' => [
                'message' => 'If an account with that email exists, a password reset link has been sent.',
            ],
        ]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        try {
            $this->passwordResetService->resetPassword($request->validated());
        } catch (InvalidPasswordResetTokenException $exception) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_RESET_TOKEN',
                    'message' => $exception->getMessage(),
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'message' => 'Password has been reset successfully.',
            ],
        ]);
    }
}
