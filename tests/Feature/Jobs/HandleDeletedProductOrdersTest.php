<?php

namespace Tests\Feature\Jobs;

use App\Enums\OrderStatus;
use App\Jobs\HandleDeletedProductOrders;
use App\Mail\OrderCancelledMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleDeletedProductOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->customer = User::factory()->create();
        $this->product = Product::factory()->for(User::factory())->withStock(10)->create();
    }

    #[Test]
    public function an_order_holding_only_that_product_is_cancelled(): void
    {
        $order = $this->orderWith([[$this->product, 2]]);

        $this->product->delete();
        $this->runJob();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(0, $order->items()->count());

        Mail::assertSent(
            OrderCancelledMail::class,
            fn (OrderCancelledMail $mail) => $mail->hasTo($this->customer->email),
        );
    }

    #[Test]
    public function an_order_with_other_items_keeps_them_and_is_re_totalled(): void
    {
        $keeper = Product::factory()->for(User::factory())->pricedAt(199.00)->withStock(10)->create();

        // 199.00 x 2 = 398.00 stays; the deleted product's line goes.
        $order = $this->orderWith([[$this->product, 2], [$keeper, 2]]);

        $this->product->delete();
        $this->runJob();

        $order->refresh();

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(1, $order->items()->count());
        $this->assertSame($keeper->id, $order->items->first()->product_id);
        $this->assertSame('398.00', $order->total_amount);
    }

    #[Test]
    public function the_surviving_items_keep_their_stock_reserved(): void
    {
        $keeper = Product::factory()->for(User::factory())->withStock(10)->create();
        $keeper->decrement('stock', 3);

        $this->orderWith([[$this->product, 2], [$keeper, 3]]);

        $this->product->delete();
        $this->runJob();

        // Still being shipped, so those units stay deducted.
        $this->assertSame(7, $keeper->fresh()->stock);
    }

    #[Test]
    public function it_handles_a_processing_order_too(): void
    {
        $order = $this->orderWith([[$this->product, 2]], OrderStatus::Processing);

        $this->product->delete();
        $this->runJob();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    #[Test]
    public function it_leaves_completed_and_cancelled_orders_alone(): void
    {
        $completed = $this->orderWith([[$this->product, 2]], OrderStatus::Completed);
        $cancelled = $this->orderWith([[$this->product, 2]], OrderStatus::Cancelled);

        $this->product->delete();
        $this->runJob();

        $this->assertSame(OrderStatus::Completed, $completed->fresh()->status);
        $this->assertSame(1, $completed->items()->count());
        $this->assertSame(OrderStatus::Cancelled, $cancelled->fresh()->status);
        Mail::assertNothingSent();
    }

    #[Test]
    public function it_ignores_orders_that_do_not_contain_the_product(): void
    {
        $other = Product::factory()->for(User::factory())->withStock(10)->create();
        $order = $this->orderWith([[$other, 2]]);

        $this->product->delete();
        $this->runJob();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        Mail::assertNothingSent();
    }

    #[Test]
    public function it_exits_quietly_when_the_product_never_existed(): void
    {
        (new HandleDeletedProductOrders(999999))->handle(app(OrderService::class));

        Mail::assertNothingSent();
    }

    /** @param array<int, array{0: Product, 1: int}> $lines */
    private function orderWith(array $lines, OrderStatus $status = OrderStatus::Pending): Order
    {
        $order = Order::factory()->for($this->customer)->status($status)->create();

        foreach ($lines as [$product, $quantity]) {
            OrderItem::factory()->for($order)->forProduct($product, $quantity)->create();
        }

        return $order->load('items');
    }

    private function runJob(): void
    {
        (new HandleDeletedProductOrders($this->product->id))->handle(app(OrderService::class));
    }
}
