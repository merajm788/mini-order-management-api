<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{user: User, token: string}
     */
    public function register(array $data): array
    {
        // device_name only names the token, so it is not part of the user row.
        // The model's 'hashed' cast takes care of the password.
        $user = $this->users->create(
            Arr::only($data, ['name', 'email', 'password'])
        );

        return $this->issueTokenFor($user, $data['device_name'] ?? 'api');
    }

    /**
     * @return array{user: User, token: string}
     *
     * @throws ValidationException
     */
    public function login(string $email, string $password, string $device = 'api'): array
    {
        $user = $this->users->findByEmail($email);

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['email' => [__('auth.failed')]]);
        }

        return $this->issueTokenFor($user, $device);
    }

    /** Revokes only the token that made this request; other devices stay in. */
    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    /** @return array{user: User, token: string} */
    private function issueTokenFor(User $user, string $device): array
    {
        return [
            'user' => $user,
            'token' => $user->createToken($device, ['*'], now()->addDays(30))->plainTextToken,
        ];
    }
}
