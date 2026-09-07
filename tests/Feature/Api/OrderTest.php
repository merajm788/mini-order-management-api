<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Jobs\ProcessOrder;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create();
        $this->merchant = User::factory()->create();

        Queue::fake();
    }

    #[Test]
    public function a_user_can_place_an_order_and_the_total_is_calculated(): void
    {
        $mouse = Product::factory()->for($this->merchant)->pricedAt(24.99)->withStock(10)->create();
        $keyboard = Product::factory()->for($this->merchant)->pricedAt(89.50)->withStock(5)->create();

        $response = $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [
                ['product_id' => $mouse->id, 'quantity' => 2],
                ['product_id' => $keyboard->id, 'quantity' => 1],
            ],
            'notes' => 'Please deliver after 6pm.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonCount(2, 'data.items');

        // 24.99 * 2 + 89.50 = 139.48
        $this->assertEqualsWithDelta(139.48, $response->json('data.total_amount'), 0.001);
    }

    #[Test]
    public function placing_an_order_reduces_the_product_stock(): void
    {
        $product = Product::factory()->for($this->merchant)->withStock(10)->create();

        $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertCreated();

        $this->assertSame(7, $product->fresh()->stock);
    }

    #[Test]
    public function order_items_snapshot_the_product_name_and_price(): void
    {
        $product = Product::factory()->for($this->merchant)
            ->pricedAt(50.00)->withStock(10)->create(['name' => 'Original Name']);

        $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        // Renaming and repricing the product must not rewrite history.
        $product->update(['name' => 'Renamed', 'price' => 999.00]);

        $this->assertDatabaseHas('order_items', [
            'product_id' => $product->id,
            'product_name' => 'Original Name',
            'unit_price' => '50.00',
        ]);
    }

    #[Test]
    public function an_order_is_rejected_when_stock_is_insufficient(): void
    {
        $product = Product::factory()->for($this->merchant)->withStock(2)->create();

        $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.stock.0.product_id', $product->id)
            ->assertJsonPath('errors.stock.0.requested', 5)
            ->assertJsonPath('errors.stock.0.available', 2);
    }

    #[Test]
    public function a_rejected_order_leaves_stock_and_the_orders_table_untouched(): void
    {
        $product = Product::factory()->for($this->merchant)->withStock(2)->create();

        $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ])->assertStatus(422);

        $this->assertSame(2, $product->fresh()->stock);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    #[Test]
    public function a_partially_unfulfillable_order_is_rejected_entirely(): void
    {
        $ok = Product::factory()->for($this->merchant)->withStock(10)->create();
        $short = Product::factory()->for($this->merchant)->withStock(1)->create();

        $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [
                ['product_id' => $ok->id, 'quantity' => 2],
                ['product_id' => $short->id, 'quantity' => 5],
            ],
        ])->assertStatus(422);

        // All or nothing: the fulfillable line was rolled back too.
        $this->assertSame(10, $ok->fresh()->stock);
        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function every_shortage_is_reported_in_one_response(): void
    {
        $a = Product::factory()->for($this->merchant)->withStock(1)->create();
        $b = Product::factory()->for($this->merchant)->withStock(1)->create();

        $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [
                ['product_id' => $a->id, 'quantity' => 9],
                ['product_id' => $b->id, 'quantity' => 9],
            ],
        ])->assertStatus(422)->assertJsonCount(2, 'errors.stock');
    }

    #[Test]
    public function an_inactive_product_cannot_be_ordered(): void
    {
        $product = Product::factory()->for($this->merchant)->inactive()->withStock(10)->create();

        $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'One or more products are unavailable for ordering.')
            ->assertJsonPath('errors.products.0', $product->id);
    }

    #[Test]
    public function an_out_of_stock_product_cannot_be_ordered(): void
    {
        $product = Product::factory()->for($this->merchant)->outOfStock()->create();

        $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonPath('errors.stock.0.available', 0);
    }

    #[Test]
    public function an_order_must_contain_at_least_one_item(): void
    {
        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/orders', ['items' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    #[Test]
    public function ordering_a_nonexistent_product_is_rejected(): void
    {
        $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => 999999, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');
    }

    #[Test]
    public function a_zero_or_negative_quantity_is_rejected(): void
    {
        $product = Product::factory()->for($this->merchant)->withStock(10)->create();

        $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 0]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
    }

    #[Test]
    public function placing_an_order_requires_authentication(): void
    {
        $product = Product::factory()->for($this->merchant)->withStock(10)->create();

        $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertUnauthorized();
    }

    #[Test]
    public function placing_an_order_queues_the_processing_job(): void
    {
        $product = Product::factory()->for($this->merchant)->withStock(10)->create();

        $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        Queue::assertPushed(ProcessOrder::class, function (ProcessOrder $job): bool {
            return $job->orderId === Order::first()->id;
        });
    }

    #[Test]
    public function a_user_sees_only_their_own_orders(): void
    {
        Order::factory()->count(2)->for($this->customer)->create();
        Order::factory()->count(3)->for(User::factory())->create();

        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function orders_can_be_filtered_by_status(): void
    {
        Order::factory()->count(2)->for($this->customer)->status(OrderStatus::Completed)->create();
        Order::factory()->for($this->customer)->status(OrderStatus::Pending)->create();

        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/orders?status=completed')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function an_unknown_status_filter_is_rejected(): void
    {
        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/orders?status=teleported')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    #[Test]
    public function a_user_can_view_their_own_order_with_its_items(): void
    {
        $product = Product::factory()->for($this->merchant)->withStock(10)->create();

        $orderId = $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->json('data.id');

        $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.id', $orderId)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 2);
    }

    #[Test]
    public function a_user_cannot_view_someone_elses_order(): void
    {
        $order = Order::factory()->for(User::factory())->create();

        // 404, not 403: the response must not confirm the order exists.
        $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Order not found.');
    }

    #[Test]
    public function cancelling_an_order_restores_the_stock(): void
    {
        $product = Product::factory()->for($this->merchant)->withStock(10)->create();

        $orderId = $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->json('data.id');

        $this->assertSame(6, $product->fresh()->stock);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(10, $product->fresh()->stock);
    }

    #[Test]
    public function a_completed_order_cannot_be_cancelled(): void
    {
        $order = Order::factory()->for($this->customer)->status(OrderStatus::Completed)->create();

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    #[Test]
    public function repeating_a_product_in_one_order_is_rejected(): void
    {
        $product = Product::factory()->for($this->merchant)->withStock(10)->create();

        $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');
    }
}
