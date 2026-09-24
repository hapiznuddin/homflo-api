<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireVerifiedEmail
{
    /**
     * Handle an incoming request.
     *
     * Runs after auth:sanctum. Unverified users receive the project error
     * envelope instead of the framework default response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'EMAIL_NOT_VERIFIED',
                    'message' => 'Please verify your email address before continuing.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
