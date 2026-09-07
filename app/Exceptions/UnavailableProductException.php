<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Raised when an order references a product that is missing, soft deleted or
 * inactive. It renders itself, so controllers never need a try/catch.
 */
class UnavailableProductException extends Exception
{
    /** @param array<int, int> $productIds */
    public function __construct(public readonly array $productIds)
    {
        parent::__construct('One or more products are unavailable for ordering.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => ['products' => array_values($this->productIds)],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
