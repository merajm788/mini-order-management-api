<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Mail\OrderCancelledMail;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * When a product is deleted, orders still waiting on it can never be fulfilled.
 * Cancels each one — which returns the rest of its stock — and tells the
 * customer why.
 */
class CancelOrdersForDeletedProduct implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $productId,
    ) {
        $this->onQueue('orders');
    }

    public function handle(OrderService $orders): void
    {
        // withTrashed: the product is soft deleted by the time this runs.
        $product = Product::withTrashed()->find($this->productId);

        if (! $product) {
            return;
        }

        Order::query()
            ->whereIn('status', [OrderStatus::Pending, OrderStatus::Processing])
            ->whereHas('items', fn ($q) => $q->where('product_id', $this->productId))
            ->with(['items', 'user'])
            ->each(function (Order $order) use ($orders, $product): void {
                $orders->cancelOrder($order);

                Mail::to($order->user->email)->send(new OrderCancelledMail($order, $product));
            });
    }
}
