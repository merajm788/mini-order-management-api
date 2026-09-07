<?php

namespace App\Repositories;

use App\Models\User;

/**
 * All user database access.
 */
class UserRepository
{
    public function findByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): User
    {
        return User::query()->create($attributes);
    }
}
