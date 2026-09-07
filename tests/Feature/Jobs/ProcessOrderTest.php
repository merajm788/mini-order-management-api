<?php

namespace Tests\Feature\Jobs;

use App\Enums\OrderStatus;
use App\Jobs\ProcessOrder;
use App\Mail\OrderPlacedMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    #[Test]
    public function it_moves_a_pending_order_to_processing(): void
    {
        $order = $this->order(OrderStatus::Pending);

        (new ProcessOrder($order->id))->handle();

        $this->assertSame(OrderStatus::Processing, $order->fresh()->status);
    }

    #[Test]
    public function it_emails_the_customer_a_confirmation(): void
    {
        $order = $this->order(OrderStatus::Pending);

        (new ProcessOrder($order->id))->handle();

        Mail::assertSent(OrderPlacedMail::class, function (OrderPlacedMail $mail) use ($order): bool {
            return $mail->order->id === $order->id
                && $mail->hasTo($order->user->email);
        });
    }

    #[Test]
    public function it_skips_an_order_that_was_cancelled_before_pickup(): void
    {
        $order = $this->order(OrderStatus::Cancelled);

        (new ProcessOrder($order->id))->handle();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        Mail::assertNothingSent();
    }

    #[Test]
    public function it_exits_quietly_when_the_order_no_longer_exists(): void
    {
        (new ProcessOrder(999999))->handle();

        Mail::assertNothingSent();
    }

    #[Test]
    public function it_is_queued_on_the_orders_queue(): void
    {
        $this->assertSame('orders', (new ProcessOrder(1))->queue);
    }

    #[Test]
    public function it_retries_a_failure_three_times(): void
    {
        $this->assertSame(3, (new ProcessOrder(1))->tries);
    }

    private function order(OrderStatus $status): Order
    {
        $user = User::factory()->create();
        $product = Product::factory()->for(User::factory())->create();

        $order = Order::factory()->for($user)->status($status)->create();
        OrderItem::factory()->for($order)->forProduct($product, 2)->create();

        return $order;
    }
}
