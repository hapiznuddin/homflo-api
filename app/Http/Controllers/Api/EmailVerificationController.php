<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\EmailVerificationMismatchException;
use App\Exceptions\VerificationUserNotFoundException;
use App\Http\Controllers\Controller;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

    /**
     * Process a signed verification link from the verification email.
     *
     * Browser requests (email-link clicks) are redirected to the Nuxt
     * frontend status page; JSON clients keep the API envelope. The
     * destination comes from the env-driven frontend_url config so
     * production never points at localhost, and the redirect carries no
     * token, hash, or session data.
     */
    public function verify(Request $request, string $id, string $hash): JsonResponse|RedirectResponse
    {
        try {
            $verified = $this->verificationService->verify($id, $hash);
        } catch (VerificationUserNotFoundException $exception) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'USER_NOT_FOUND',
                    'message' => $exception->getMessage(),
                ],
            ], 404);
        } catch (EmailVerificationMismatchException $exception) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => $exception->getMessage(),
                ],
            ], 403);
        }

        if (! $request->expectsJson()) {
            return redirect()->away(
                rtrim((string) config('app.frontend_url'), '/').'/verify-email'
            );
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
