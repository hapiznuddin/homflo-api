<?php

namespace App\Services\Auth;

use App\Exceptions\InvalidCurrentPasswordException;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;

class PasswordChangeService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
    ) {}

    /**
     * Change the authenticated user's password.
     *
     * @throws InvalidCurrentPasswordException
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw new InvalidCurrentPasswordException;
        }

        $this->users->update($user, [
            'password' => Hash::make($newPassword),
        ]);
    }
}
