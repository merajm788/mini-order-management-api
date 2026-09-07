<?php

namespace Tests\Unit\Services;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\UnavailableProductException;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Services\OrderService;
use Illuminate\Database\Eloquent\Collection;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exercises the pricing and stock rules against mocked repositories, so these
 * assertions never touch the database. This is the concrete pay-off of coding
 * the service against interfaces rather than Eloquent.
 */
class OrderServiceTest extends TestCase
{
    private ProductRepository&MockInterface $products;

    private OrderRepository&MockInterface $orders;

    private OrderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->products = Mockery::mock(ProductRepository::class);
        $this->orders = Mockery::mock(OrderRepository::class);
        $this->service = new OrderService($this->orders, $this->products);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    #[Test]
    public function it_rejects_an_order_whose_product_is_inactive(): void
    {
        $product = $this->product(id: 1, price: '10.00', stock: 50, active: false);

        $this->products->shouldReceive('lockForOrdering')->once()->andReturn(new Collection([$product]));

        $this->expectException(UnavailableProductException::class);

        $this->service->placeOrder($this->user(), [['product_id' => 1, 'quantity' => 1]]);
    }

    #[Test]
    public function it_rejects_an_order_that_exceeds_available_stock(): void
    {
        $product = $this->product(id: 1, price: '10.00', stock: 3);

        $this->products->shouldReceive('lockForOrdering')->once()->andReturn(new Collection([$product]));

        try {
            $this->service->placeOrder($this->user(), [['product_id' => 1, 'quantity' => 4]]);
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException $e) {
            $this->assertSame([[
                'product_id' => 1,
                'product_name' => 'Test Product',
                'requested' => 4,
                'available' => 3,
            ]], $e->shortages);
        }
    }

    #[Test]
    public function it_reports_a_shortage_for_every_affected_product_at_once(): void
    {
        $a = $this->product(id: 1, price: '10.00', stock: 1, name: 'A');
        $b = $this->product(id: 2, price: '20.00', stock: 1, name: 'B');

        $this->products->shouldReceive('lockForOrdering')->once()->andReturn(new Collection([$a, $b]));

        try {
            $this->service->placeOrder($this->user(), [
                ['product_id' => 1, 'quantity' => 5],
                ['product_id' => 2, 'quantity' => 5],
            ]);
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException $e) {
            $this->assertCount(2, $e->shortages);
        }
    }

    #[Test]
    public function it_calculates_the_total_from_unit_price_times_quantity(): void
    {
        $a = $this->product(id: 1, price: '24.99', stock: 100, name: 'Mouse');
        $b = $this->product(id: 2, price: '89.50', stock: 100, name: 'Keyboard');

        $this->products->shouldReceive('lockForOrdering')->once()->andReturn(new Collection([$a, $b]));
        $this->products->shouldReceive('reduceStock')->twice();

        $captured = null;
        $this->orders->shouldReceive('create')->once()
            ->andReturnUsing(function (array $attributes) use (&$captured): Order {
                $captured = $attributes;

                return tap(new Order($attributes), fn (Order $o) => $o->id = 1);
            });
        $this->orders->shouldReceive('addItems')->once();

        $this->service->placeOrder($this->user(), [
            ['product_id' => 1, 'quantity' => 2],
            ['product_id' => 2, 'quantity' => 1],
        ]);

        // 24.99 * 2 + 89.50 * 1, computed with bcmath rather than floats.
        $this->assertSame('139.48', $captured['total_amount']);
    }

    #[Test]
    public function it_snapshots_the_product_name_and_price_onto_each_line_item(): void
    {
        $product = $this->product(id: 1, price: '19.99', stock: 10, name: 'Snapshot Me');

        $this->products->shouldReceive('lockForOrdering')->once()->andReturn(new Collection([$product]));
        $this->products->shouldReceive('reduceStock')->once();
        $this->orders->shouldReceive('create')->once()
            ->andReturn(tap(new Order, fn (Order $o) => $o->id = 1));

        $items = null;
        $this->orders->shouldReceive('addItems')->once()
            ->andReturnUsing(function (Order $order, array $lines) use (&$items): void {
                $items = $lines;
            });

        $this->service->placeOrder($this->user(), [['product_id' => 1, 'quantity' => 3]]);

        $this->assertSame('Snapshot Me', $items[0]['product_name']);
        $this->assertSame('19.99', $items[0]['unit_price']);
        $this->assertSame('59.97', $items[0]['subtotal']);
    }

    #[Test]
    public function it_deducts_exactly_the_ordered_quantity_from_stock(): void
    {
        $product = $this->product(id: 1, price: '5.00', stock: 10);

        $this->products->shouldReceive('lockForOrdering')->once()->andReturn(new Collection([$product]));
        $this->orders->shouldReceive('create')->once()
            ->andReturn(tap(new Order, fn (Order $o) => $o->id = 1));
        $this->orders->shouldReceive('addItems')->once();

        $deducted = null;
        $this->products->shouldReceive('reduceStock')->once()
            ->andReturnUsing(function (Product $p, int $quantity) use (&$deducted): void {
                $deducted = $quantity;
            });

        $this->service->placeOrder($this->user(), [['product_id' => 1, 'quantity' => 4]]);

        $this->assertSame(4, $deducted);
    }

    private function user(): User
    {
        return tap(new User(['name' => 'Buyer', 'email' => 'buyer@example.com']),
            fn (User $u) => $u->id = 99);
    }

    private function product(
        int $id,
        string $price,
        int $stock,
        bool $active = true,
        string $name = 'Test Product',
    ): Product {
        $product = new Product([
            'name' => $name,
            'price' => $price,
            'stock' => $stock,
            'is_active' => $active,
        ]);
        $product->id = $id;

        return $product;
    }
}
