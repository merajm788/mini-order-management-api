<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Raised when an order asks for more units than a product has. It renders
 * itself, so controllers never need a try/catch.
 */
class InsufficientStockException extends Exception
{
    /** @param array<int, array{product_id: int, product_name: string, requested: int, available: int}> $shortages */
    public function __construct(public readonly array $shortages)
    {
        parent::__construct('One or more products do not have enough stock.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => ['stock' => $this->shortages],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
