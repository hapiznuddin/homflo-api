<?php

namespace App\Services\Auth;

use App\Exceptions\EmailVerificationMismatchException;
use App\Models\User;
use Illuminate\Auth\Events\Verified;

class EmailVerificationService
{
    /**
     * Send the verification notification to the user.
     */
    public function sendVerification(User $user): void
    {
        $user->sendEmailVerificationNotification();
    }

    /**
     * Mark the authenticated user's email as verified.
     *
     * The signed middleware guarantees URL integrity and expiry; this method
     * binds the link to the authenticated user server-side.
     *
     * @throws EmailVerificationMismatchException
     */
    public function verify(User $user, string $id, string $hash): bool
    {
        if ((string) $user->getKey() !== (string) $id
            || ! hash_equals(sha1($user->getEmailForVerification()), (string) $hash)
        ) {
            throw new EmailVerificationMismatchException;
        }

        if ($user->hasVerifiedEmail()) {
            return false;
        }

        $user->markEmailAsVerified();

        event(new Verified($user));

        return true;
    }
}
