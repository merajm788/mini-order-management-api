<x-mail::message>
# Thanks for your order, {{ $customerName }}!

We have received order **{{ $order->order_number }}** and it is now being processed.

<x-mail::table>
| Product | Qty | Unit price | Subtotal |
|:--------|:---:|-----------:|---------:|
@foreach ($order->items as $item)
| {{ $item->product_name }} | {{ $item->quantity }} | {{ number_format((float) $item->unit_price, 2) }} | {{ number_format((float) $item->subtotal, 2) }} |
@endforeach
</x-mail::table>

**Order total: {{ number_format((float) $order->total_amount, 2) }}**

@if ($order->notes)
**Your note:** {{ $order->notes }}
@endif

<x-mail::button :url="config('app.url').'/api/v1/orders/'.$order->id">
View your order
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
