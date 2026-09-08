<x-mail::message>
@if ($order->status === App\Enums\OrderStatus::Cancelled)
# Your order has been cancelled

Hi {{ $customerName }},

**{{ $productName }}** is no longer available, and it was the only item on order
**{{ $order->order_number }}**, so the order has been cancelled. Nothing has
been charged.
@else
# An item has been removed from your order

Hi {{ $customerName }},

**{{ $productName }}** is no longer available, so we have removed it from order
**{{ $order->order_number }}**. Everything else is still on its way, and your
total has been adjusted.

<x-mail::table>
| Product | Qty | Unit price | Subtotal |
|:--------|:---:|-----------:|---------:|
@foreach ($order->items as $item)
| {{ $item->product_name }} | {{ $item->quantity }} | {{ number_format((float) $item->unit_price, 2) }} | {{ number_format((float) $item->subtotal, 2) }} |
@endforeach
</x-mail::table>

**New order total: {{ number_format((float) $order->total_amount, 2) }}**
@endif

Sorry for the inconvenience.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
