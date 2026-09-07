<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 5);
        $unitPrice = fake()->randomFloat(2, 5, 500);

        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'product_name' => ucfirst(fake()->words(3, true)),
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'subtotal' => bcmul((string) $unitPrice, (string) $quantity, 2),
        ];
    }

    /**
     * Builds a line item that mirrors a real product's name and price.
     */
    public function forProduct(Product $product, int $quantity): static
    {
        return $this->state(fn (): array => [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit_price' => $product->price,
            'quantity' => $quantity,
            'subtotal' => bcmul((string) $product->price, (string) $quantity, 2),
        ]);
    }
}
