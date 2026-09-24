<?php

namespace App\Services\Auth;

use App\Exceptions\EmailVerificationMismatchException;
use App\Exceptions\VerificationUserNotFoundException;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Auth\Events\Verified;

class EmailVerificationService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
    ) {}

    /**
     * Send the verification notification to the user.
     */
    public function sendVerification(User $user): void
    {
        $user->sendEmailVerificationNotification();
    }

    /**
     * Mark the email as verified for the user identified by the signed route id.
     *
     * No authenticated session is required: the signed middleware guarantees
     * URL integrity and expiry, and this method binds the link to the user
     * resolved server-side from the route id.
     *
     * @throws VerificationUserNotFoundException
     * @throws EmailVerificationMismatchException
     */
    public function verify(string $id, string $hash): bool
    {
        $user = $this->users->findById((string) $id);

        if ($user === null) {
            throw new VerificationUserNotFoundException;
        }

        if (! hash_equals(sha1($user->getEmailForVerification()), (string) $hash)) {
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
