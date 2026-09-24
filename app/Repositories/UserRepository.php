<?php

namespace App\Repositories;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;

class UserRepository implements UserRepositoryInterface
{
    public function findById(string $id): ?User
    {
        return User::query()
            ->find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email, 'UTF-8')])
            ->first();
    }

    public function findByUsername(string $username): ?User
    {
        return User::query()
            ->where('username', $username)
            ->first();
    }

    public function create(array $data): User
    {
        return User::query()->create($data);
    }

    public function update(User $user, array $data): User
    {
        $user->update($data);

        return $user->refresh();
    }
}
