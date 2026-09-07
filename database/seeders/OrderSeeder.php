<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
        $customer = User::query()->where('email', 'customer@example.com')->firstOrFail();
        $products = Product::query()->available()->get();

        if ($products->isEmpty()) {
            return;
        }

        $statuses = [
            OrderStatus::Completed,
            OrderStatus::Processing,
            OrderStatus::Pending,
            OrderStatus::Cancelled,
            OrderStatus::Completed,
        ];

        foreach ($statuses as $status) {
            DB::transaction(function () use ($customer, $products, $status): void {
                $picked = $products->random(min(3, $products->count()));

                $order = Order::factory()->for($customer)->status($status)->create([
                    'total_amount' => 0,
                ]);

                $total = '0.00';

                foreach ($picked as $product) {
                    $quantity = random_int(1, 3);

                    OrderItem::factory()->for($order)->forProduct($product, $quantity)->create();

                    $total = bcadd($total, bcmul((string) $product->price, (string) $quantity, 2), 2);

                    // Cancelled orders never consumed stock, so only deduct for
                    // the ones that stayed alive.
                    if ($status !== OrderStatus::Cancelled) {
                        $product->decrement('stock', min($quantity, $product->stock));
                    }
                }

                $order->update(['total_amount' => $total]);
            });
        }
    }
}
