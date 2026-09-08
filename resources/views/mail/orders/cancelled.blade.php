<x-mail::message>
# Your order has been cancelled

Hi {{ $customerName }},

**{{ $productName }}** is no longer available, so order
**{{ $order->order_number }}** has been cancelled and nothing has been charged.

<x-mail::table>
| Product | Qty | Unit price | Subtotal |
|:--------|:---:|-----------:|---------:|
@foreach ($order->items as $item)
| {{ $item->product_name }} | {{ $item->quantity }} | {{ number_format((float) $item->unit_price, 2) }} | {{ number_format((float) $item->subtotal, 2) }} |
@endforeach
</x-mail::table>

**Order total: {{ number_format((float) $order->total_amount, 2) }}**

Sorry for the inconvenience.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
