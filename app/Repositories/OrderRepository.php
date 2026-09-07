<?php

namespace App\Repositories;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * All order and order-item database access.
 */
class OrderRepository
{
    public function getUserOrders(int $userId, ?OrderStatus $status = null, int $perPage = 15): LengthAwarePaginator
    {
        return Order::query()
            ->where('user_id', $userId)
            ->when($status, fn ($query) => $query->where('status', $status))
            // Counts the items in the same query instead of one query per order.
            ->withCount('items')
            ->latest()
            ->paginate($perPage);
    }

    /** Scoped to the owner, so nobody can read another user's order by guessing its id. */
    public function findUserOrder(int $orderId, int $userId): ?Order
    {
        return Order::query()
            ->where('user_id', $userId)
            ->with('items')
            ->find($orderId);
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Order
    {
        return Order::query()->create($attributes);
    }

    /** @param array<int, array<string, mixed>> $items */
    public function addItems(Order $order, array $items): void
    {
        $order->items()->createMany($items);
    }

    public function updateStatus(Order $order, OrderStatus $status): Order
    {
        $order->update(['status' => $status]);

        return $order->refresh();
    }
}
