<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(3, true));

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'sku' => Str::upper(Str::slug(Str::limit($name, 20, ''))).'-'.Str::upper(Str::random(4)),
            'description' => fake()->sentence(12),
            'price' => fake()->randomFloat(2, 5, 2500),
            'stock' => fake()->numberBetween(0, 200),
            'is_active' => true,
        ];
    }

    public function outOfStock(): static
    {
        return $this->state(fn (): array => ['stock' => 0]);
    }

    public function withStock(int $stock): static
    {
        return $this->state(fn (): array => ['stock' => $stock]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function pricedAt(float $price): static
    {
        return $this->state(fn (): array => ['price' => $price]);
    }
}
