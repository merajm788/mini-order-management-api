<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderCancelledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly Product $product,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Order cancelled — {$this->order->order_number}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.orders.cancelled',
            with: [
                'order' => $this->order,
                'productName' => $this->product->name,
                'customerName' => $this->order->user->name,
            ],
        );
    }
}
