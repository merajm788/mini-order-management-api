<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->auth->register($request->validated());

        return ApiResponse::created($this->buildAuthResponse($result), 'Registration successful.');
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            $request->validated('email'),
            $request->validated('password'),
            $request->validated('device_name', 'api'),
        );

        return ApiResponse::success($this->buildAuthResponse($result), 'Login successful.');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user());

        return ApiResponse::success(message: 'Logged out successfully.');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(new UserResource($request->user()), 'Authenticated user.');
    }

    /**
     * @param  array{user: User, token: string}  $result
     * @return array<string, mixed>
     */
    private function buildAuthResponse(array $result): array
    {
        return [
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
            'token_type' => 'Bearer',
        ];
    }
}
