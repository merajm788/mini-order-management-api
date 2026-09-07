<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Counters live in the cache and would otherwise leak between tests.
        RateLimiter::clear('auth');
        $this->app['cache']->store()->flush();
    }

    #[Test]
    public function login_attempts_are_throttled_after_the_configured_limit(): void
    {
        config(['api.rate_limits.auth' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/login', [
                'email' => 'nobody@example.com',
                'password' => 'wrong',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong',
        ])
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Too many authentication attempts. Please try again in a minute.');
    }

    #[Test]
    public function registrations_share_the_auth_throttle(): void
    {
        config(['api.rate_limits.auth' => 2]);

        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/v1/register', [
                'name' => "User {$i}",
                'email' => "user{$i}@example.com",
                'password' => 'secret-pass-1',
                'password_confirmation' => 'secret-pass-1',
            ])->assertCreated();
        }

        $this->postJson('/api/v1/register', [
            'name' => 'One Too Many',
            'email' => 'toomany@example.com',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
        ])->assertStatus(429);
    }

    #[Test]
    public function order_creation_has_its_own_stricter_limit(): void
    {
        config(['api.rate_limits.orders' => 2]);

        $user = User::factory()->create();
        $product = Product::factory()->for(User::factory())->withStock(100)->create();

        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ])->assertCreated();
        }

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(429);
    }

    #[Test]
    public function the_order_limit_is_tracked_per_user_not_globally(): void
    {
        config(['api.rate_limits.orders' => 1]);

        $product = Product::factory()->for(User::factory())->withStock(100)->create();
        $payload = ['items' => [['product_id' => $product->id, 'quantity' => 1]]];

        $first = User::factory()->create();
        $this->actingAs($first, 'sanctum')->postJson('/api/v1/orders', $payload)->assertCreated();
        $this->actingAs($first, 'sanctum')->postJson('/api/v1/orders', $payload)->assertStatus(429);

        // A different customer must not inherit the first one's exhausted quota.
        $second = User::factory()->create();
        $this->actingAs($second, 'sanctum')->postJson('/api/v1/orders', $payload)->assertCreated();
    }

    #[Test]
    public function browsing_products_is_not_blocked_by_the_auth_limiter(): void
    {
        config(['api.rate_limits.auth' => 1]);

        Product::factory()->count(2)->for(User::factory())->create();

        // Well past the auth limit, and still fine: the limiters are separate.
        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/v1/products')->assertOk();
        }
    }
}
