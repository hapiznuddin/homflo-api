<?php

namespace App\Services\Auth;

use App\Exceptions\InvalidPasswordResetTokenException;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class PasswordResetService
{
    /**
     * Send a password reset link through Laravel's password broker.
     *
     * The broker result is intentionally ignored so unknown emails receive
     * the same response as existing ones (enumeration resistance).
     */
    public function requestResetLink(string $email): void
    {
        Password::broker()->sendResetLink(['email' => $email]);
    }

    /**
     * Reset the password through Laravel's password broker.
     *
     * The broker owns token verification, expiry, hashing, and invalidation.
     *
     * @param  array{email: string, token: string, password: string}  $credentials
     *
     * @throws InvalidPasswordResetTokenException
     */
    public function resetPassword(array $credentials): void
    {
        $status = Password::broker()->reset($credentials, function (User $user, string $password) {
            $user->forceFill([
                'password' => Hash::make($password),
            ])->save();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw new InvalidPasswordResetTokenException;
        }
    }
}
