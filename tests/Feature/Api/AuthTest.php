<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_visitor_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Alex Doe',
            'email' => 'alex@example.com',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email'], 'token', 'token_type']]);

        $this->assertDatabaseHas('users', ['email' => 'alex@example.com']);
        // The plain password must never be stored.
        $this->assertNotSame('secret-pass-1', User::firstWhere('email', 'alex@example.com')->password);
    }

    #[Test]
    public function registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/register', [
            'name' => 'Someone Else',
            'email' => 'taken@example.com',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    #[Test]
    public function registration_requires_a_matching_password_confirmation(): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Alex Doe',
            'email' => 'alex@example.com',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'different-pass',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    #[Test]
    public function a_user_can_log_in_with_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => 'correct-password',
        ]);

        $this->postJson('/api/v1/login', [
            'email' => 'user@example.com',
            'password' => 'correct-password',
        ])->assertOk()->assertJsonPath('success', true)->assertJsonStructure(['data' => ['token']]);
    }

    #[Test]
    public function logging_in_with_a_wrong_password_fails(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => 'correct-password',
        ]);

        $this->postJson('/api/v1/login', [
            'email' => 'user@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    #[Test]
    public function logging_in_with_an_unknown_email_gives_the_same_error(): void
    {
        // Identical response to a wrong password: no account enumeration.
        $this->postJson('/api/v1/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    #[Test]
    public function an_authenticated_user_can_fetch_their_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    #[Test]
    public function logging_out_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $keptToken = $user->createToken('other-device')->plainTextToken;
        $currentToken = $user->createToken('this-device')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$currentToken}")
            ->postJson('/api/v1/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(1, $user->tokens()->count());

        // The other device is still signed in.
        $this->withHeader('Authorization', "Bearer {$keptToken}")
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    #[Test]
    public function protected_endpoints_reject_requests_without_a_token(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    }
}
