<?php

namespace App\Providers;

use App\Http\Responses\ApiResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Fail loudly in development on a missing eager load or a stray attribute.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(config('api.rate_limits.api'))
            ->by($request->user()?->id ?: $request->ip()));

        // Login and register are the brute-force surface, so they are tighter
        // and always keyed by IP.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(config('api.rate_limits.auth'))
            ->by($request->ip())
            ->response(fn () => ApiResponse::error(
                'Too many authentication attempts. Please try again in a minute.',
                status: Response::HTTP_TOO_MANY_REQUESTS,
            )));

        RateLimiter::for('orders', fn (Request $request) => Limit::perMinute(config('api.rate_limits.orders'))
            ->by($request->user()?->id ?: $request->ip()));
    }
}
