<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\IndexOrderRequest;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
    ) {}

    public function index(IndexOrderRequest $request): JsonResponse
    {
        $orders = $this->orders->getUserOrders(
            user: $request->user(),
            status: $request->statusFilter(),
            perPage: $request->integer('per_page', 15),
        );

        return ApiResponse::paginated(OrderResource::collection($orders), 'Orders retrieved successfully.');
    }

    /**
     * Insufficient stock and unavailable products are raised as exceptions
     * that render themselves as 422, so there is nothing to catch here.
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orders->placeOrder(
            user: $request->user(),
            items: $request->validated('items'),
            notes: $request->validated('notes'),
        );

        return ApiResponse::created(new OrderResource($order), 'Order placed successfully.');
    }

    public function show(Request $request, int $order): JsonResponse
    {
        $model = $this->orders->findUserOrder($request->user(), $order);

        // The lookup is scoped to the owner, so a miss is a plain 404 and never
        // reveals whether the order exists.
        if (! $model) {
            return ApiResponse::error('Order not found.', status: Response::HTTP_NOT_FOUND);
        }

        return ApiResponse::success(new OrderResource($model), 'Order retrieved successfully.');
    }

    public function cancel(Request $request, int $order): JsonResponse
    {
        $model = $this->orders->findUserOrder($request->user(), $order);

        if (! $model) {
            return ApiResponse::error('Order not found.', status: Response::HTTP_NOT_FOUND);
        }

        if (! $model->status->canBeCancelled()) {
            return ApiResponse::error(
                "An order with status '{$model->status->value}' can no longer be cancelled.",
                status: Response::HTTP_CONFLICT,
            );
        }

        return ApiResponse::success(
            new OrderResource($this->orders->cancelOrder($model)),
            'Order cancelled and stock restored.',
        );
    }
}
