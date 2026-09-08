<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\UnavailableProductException;
use App\Jobs\ProcessOrder;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly ProductRepository $products,
    ) {}

    /**
     * Places an order: checks stock, reduces it, calculates the total and
     * saves the line items — all inside one transaction.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     *
     * @throws InsufficientStockException|UnavailableProductException
     */
    public function placeOrder(User $user, array $items, ?string $notes = null): Order
    {
        $quantities = array_column($items, 'quantity', 'product_id');

        $order = DB::transaction(function () use ($user, $quantities, $notes): Order {
            // Sorted ids, so two carts holding the same products cannot deadlock.
            $ids = array_keys($quantities);
            sort($ids);

            $products = $this->products->lockForOrdering($ids)->keyBy('id');

            $this->ensureProductsCanBeOrdered($products, $quantities);

            [$lineItems, $total] = $this->buildOrderItems($products, $quantities);

            $order = $this->orders->create([
                'user_id' => $user->id,
                'order_number' => Order::generateNumber(),
                'status' => OrderStatus::Pending,
                'total_amount' => $total,
                'notes' => $notes,
            ]);

            $this->orders->addItems($order, $lineItems);

            foreach ($quantities as $productId => $quantity) {
                $this->products->reduceStock($products[$productId], $quantity);
            }

            return $order;
        });

        // afterCommit, so the worker can never read a rolled-back order.
        ProcessOrder::dispatch($order->id)->afterCommit();

        return $order->load('items');
    }

    public function getUserOrders(User $user, ?OrderStatus $status = null, int $perPage = 15): LengthAwarePaginator
    {
        return $this->orders->getUserOrders($user->id, $status, $perPage);
    }

    public function findUserOrder(User $user, int $orderId): ?Order
    {
        return $this->orders->findUserOrder($orderId, $user->id);
    }

    /** Cancels an order and puts its reserved units back into stock. */
    public function cancelOrder(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            foreach ($order->items as $item) {
                if ($product = $this->products->find($item->product_id)) {
                    $this->products->restoreStock($product, $item->quantity);
                }
            }

            return $this->orders->updateStatus($order, OrderStatus::Cancelled);
        });
    }

    /**
     * Verifies that every product exists, is active, and has enough stock.
     *
     * @param  Collection<int, Product>  $products
     * @param  array<int, int>  $quantities
     *
     * @throws InsufficientStockException|UnavailableProductException
     */
    private function ensureProductsCanBeOrdered(Collection $products, array $quantities): void
    {
        $unavailable = [];
        $shortages = [];

        foreach ($quantities as $productId => $quantity) {
            $product = $products->get($productId);

            if (! $product || ! $product->is_active) {
                $unavailable[] = $productId;
            } elseif ($product->stock < $quantity) {
                $shortages[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'requested' => $quantity,
                    'available' => $product->stock,
                ];
            }
        }

        if ($unavailable) {
            throw new UnavailableProductException($unavailable);
        }

        // Every shortage at once, so the client can fix the whole cart in one go.
        if ($shortages) {
            throw new InsufficientStockException($shortages);
        }
    }

    /**
     * Builds the line items and their total. Money goes through bcmath because
     * floats drift once a cart gets large.
     *
     * @param  Collection<int, Product>  $products
     * @param  array<int, int>  $quantities
     * @return array{0: array<int, array<string, mixed>>, 1: string}
     */
    private function buildOrderItems(Collection $products, array $quantities): array
    {
        $items = [];
        $total = '0.00';

        foreach ($quantities as $productId => $quantity) {
            $product = $products->get($productId);
            $subtotal = bcmul((string) $product->price, (string) $quantity, 2);

            $items[] = [
                'product_id' => $product->id,
                // Snapshot: editing the product must not rewrite past invoices.
                'product_name' => $product->name,
                'unit_price' => $product->price,
                'quantity' => $quantity,
                'subtotal' => $subtotal,
            ];

            $total = bcadd($total, $subtotal, 2);
        }

        return [$items, $total];
    }
}
