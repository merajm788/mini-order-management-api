<?php

namespace Tests\Feature\Jobs;

use App\Enums\OrderStatus;
use App\Jobs\CancelOrdersForDeletedProduct;
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

class CancelOrdersForDeletedProductTest extends TestCase
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
    public function it_cancels_a_pending_order_and_emails_the_customer(): void
    {
        $order = $this->orderFor($this->product, OrderStatus::Pending);

        $this->product->delete();
        $this->runJob();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);

        Mail::assertSent(
            OrderCancelledMail::class,
            fn (OrderCancelledMail $mail) => $mail->hasTo($this->customer->email)
                && $mail->order->is($order),
        );
    }

    #[Test]
    public function it_cancels_a_processing_order_too(): void
    {
        $order = $this->orderFor($this->product, OrderStatus::Processing);

        $this->product->delete();
        $this->runJob();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    #[Test]
    public function it_leaves_completed_and_cancelled_orders_alone(): void
    {
        $completed = $this->orderFor($this->product, OrderStatus::Completed);
        $cancelled = $this->orderFor($this->product, OrderStatus::Cancelled);

        $this->product->delete();
        $this->runJob();

        $this->assertSame(OrderStatus::Completed, $completed->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $cancelled->fresh()->status);
        Mail::assertNothingSent();
    }

    #[Test]
    public function it_ignores_orders_that_do_not_contain_the_product(): void
    {
        $other = Product::factory()->for(User::factory())->withStock(10)->create();
        $order = $this->orderFor($other, OrderStatus::Pending);

        $this->product->delete();
        $this->runJob();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        Mail::assertNothingSent();
    }

    #[Test]
    public function it_returns_the_stock_of_the_other_products_in_the_order(): void
    {
        $stillOnSale = Product::factory()->for(User::factory())->withStock(10)->create();

        $order = Order::factory()->for($this->customer)->status(OrderStatus::Pending)->create();
        OrderItem::factory()->for($order)->forProduct($this->product, 2)->create();
        OrderItem::factory()->for($order)->forProduct($stillOnSale, 3)->create();
        $stillOnSale->decrement('stock', 3);

        $this->product->delete();
        $this->runJob();

        // The deleted product keeps its stock; the survivor gets its units back.
        $this->assertSame(10, $stillOnSale->fresh()->stock);
    }

    #[Test]
    public function it_exits_quietly_when_the_product_never_existed(): void
    {
        (new CancelOrdersForDeletedProduct(999999))->handle(app(OrderService::class));

        Mail::assertNothingSent();
    }

    private function orderFor(Product $product, OrderStatus $status): Order
    {
        $order = Order::factory()->for($this->customer)->status($status)->create();
        OrderItem::factory()->for($order)->forProduct($product, 2)->create();

        return $order;
    }

    private function runJob(): void
    {
        (new CancelOrdersForDeletedProduct($this->product->id))->handle(app(OrderService::class));
    }
}
