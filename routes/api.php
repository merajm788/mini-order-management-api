<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (v1)
|--------------------------------------------------------------------------
|
| Versioned under /api/v1 so a future v2 can ship alongside this one.
| The global throttle:api limiter is applied in bootstrap/app.php; the
| stricter auth/orders limiters are attached per group below.
|
| Every {id} in this file is numeric, so a non-numeric one (/products/abc)
| simply does not match and returns 404 instead of reaching a controller.
|
*/

Route::pattern('product', '[0-9]+');
Route::pattern('order', '[0-9]+');

Route::prefix('v1')->group(function (): void {

    // --- Public -----------------------------------------------------------
    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
    });

    // Browsing the catalogue does not require a token.
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{product}', [ProductController::class, 'show']);

    // --- Authenticated ----------------------------------------------------
    Route::middleware('auth:sanctum')->group(function (): void {

        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        Route::post('products', [ProductController::class, 'store']);
        Route::put('products/{product}', [ProductController::class, 'update']);
        Route::delete('products/{product}', [ProductController::class, 'destroy']);

        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
        Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);

        Route::post('orders', [OrderController::class, 'store'])
            ->middleware('throttle:orders');
    });
});
