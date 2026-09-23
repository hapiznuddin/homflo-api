<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\EmailVerificationMismatchException;
use App\Http\Controllers\Controller;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(
        private readonly EmailVerificationService $verificationService,
    ) {}

    public function send(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'verified' => true,
                    'message' => 'Email is already verified.',
                ],
            ]);
        }

        $this->verificationService->sendVerification($user);

        return response()->json([
            'success' => true,
            'data' => [
                'verified' => false,
                'message' => 'Verification link sent.',
            ],
        ], 202);
    }

    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        try {
            $verified = $this->verificationService->verify($request->user(), $id, $hash);
        } catch (EmailVerificationMismatchException $exception) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => $exception->getMessage(),
                ],
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'verified' => true,
                'message' => $verified
                    ? 'Email has been verified.'
                    : 'Email is already verified.',
            ],
        ]);
    }
}
