<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every endpoint answers with the same envelope —
 * { success, message, data } — so a client writes one response handler.
 */
final class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        int $status = Response::HTTP_OK,
    ): JsonResponse {
        if ($data instanceof JsonResource) {
            return $data->additional(['success' => true, 'message' => $message])
                ->response()
                ->setStatusCode($status);
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public static function created(mixed $data = null, string $message = 'Created'): JsonResponse
    {
        return self::success($data, $message, Response::HTTP_CREATED);
    }

    /** Keeps Laravel's meta/links blocks alongside the envelope. */
    public static function paginated(ResourceCollection $collection, string $message = 'OK'): JsonResponse
    {
        return $collection->additional(['success' => true, 'message' => $message])->response();
    }

    /** @param array<string, mixed> $errors */
    public static function error(
        string $message,
        array $errors = [],
        int $status = Response::HTTP_BAD_REQUEST,
    ): JsonResponse {
        return response()->json(array_filter([
            'success' => false,
            'message' => $message,
            'errors' => $errors ?: null,
        ], fn ($value) => $value !== null), $status);
    }
}
