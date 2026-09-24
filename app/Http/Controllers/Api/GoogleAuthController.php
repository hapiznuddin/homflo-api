<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\GoogleOAuthService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;

class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly GoogleOAuthService $oauthService,
    ) {}

    public function redirect(): RedirectResponse
    {
        try {
            return Socialite::driver('google')->redirect();
        } catch (Exception $exception) {
            return redirect()->to($this->failureUrl());
        }
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $user = $this->oauthService->resolveUser(Socialite::driver('google')->user());
        } catch (Exception $exception) {
            return redirect()->to($this->failureUrl());
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->to($this->successUrl());
    }

    private function successUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/auth/callback';
    }

    private function failureUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/login?error=oauth_failed';
    }
}
