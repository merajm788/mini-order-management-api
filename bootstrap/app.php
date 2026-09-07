<?php

use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ThrottleRequestsException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Named limiters live in AppServiceProvider::configureRateLimiting().
        $middleware->api(prepend: [
            'throttle:api',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every API failure answers with the same envelope as every success:
        // { success, message, errors? }.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                'The given data was invalid.',
                $e->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('Unauthenticated.', status: Response::HTTP_UNAUTHORIZED);
        });

        // Laravel converts AuthorizationException into Symfony's
        // AccessDeniedHttpException before rendering, so both are handled.
        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                'This action is unauthorized.',
                status: Response::HTTP_FORBIDDEN,
            );
        });

        // Laravel wraps ModelNotFoundException in a NotFoundHttpException before
        // rendering, so a missing row and a missing route arrive as the same
        // class; getPrevious() tells them apart.
        $exceptions->render(function (NotFoundHttpException|ModelNotFoundException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $modelException = $e instanceof ModelNotFoundException ? $e : $e->getPrevious();

            if ($modelException instanceof ModelNotFoundException) {
                // "App\Models\Product" -> "Product not found." Never echoes the
                // fully qualified class name back to the client.
                $model = class_basename($modelException->getModel());

                return ApiResponse::error("{$model} not found.", status: Response::HTTP_NOT_FOUND);
            }

            return ApiResponse::error(
                'The requested endpoint does not exist.',
                status: Response::HTTP_NOT_FOUND,
            );
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                'Too many requests. Please slow down.',
                status: Response::HTTP_TOO_MANY_REQUESTS,
            );
        });
    })->create();
