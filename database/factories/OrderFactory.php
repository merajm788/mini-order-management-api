<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'order_number' => Order::generateNumber(),
            'status' => OrderStatus::Pending,
            // Overwritten by OrderSeeder once the real line items are known.
            'total_amount' => fake()->randomFloat(2, 20, 5000),
            'notes' => fake()->boolean(30) ? fake()->sentence() : null,
        ];
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
