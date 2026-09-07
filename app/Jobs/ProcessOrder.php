<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Mail\OrderPlacedMail;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Everything that happens after an order is committed: the confirmation email
 * and the pending -> processing transition.
 *
 * Carries only the order id, so the worker always reads the committed row.
 */
class ProcessOrder implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Seconds to wait between retries. */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $orderId,
    ) {
        $this->onQueue('orders');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        // Two workers must never process the same order at once.
        return [new WithoutOverlapping((string) $this->orderId)];
    }

    public function handle(): void
    {
        $order = Order::query()->with(['items', 'user'])->find($this->orderId);

        // Gone, or cancelled between dispatch and pickup: nothing to do.
        if (! $order || $order->status !== OrderStatus::Pending) {
            return;
        }

        $order->update(['status' => OrderStatus::Processing]);

        Mail::to($order->user->email)->send(new OrderPlacedMail($order));

        Log::info('Order processed.', ['order_number' => $order->order_number]);
    }
}
