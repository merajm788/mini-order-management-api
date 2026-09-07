<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rate limits (requests per minute)
    |--------------------------------------------------------------------------
    |
    | Applied by the named limiters registered in AppServiceProvider and
    | attached to routes via the throttle:<name> middleware.
    |
    */

    'rate_limits' => [
        'api' => env('RATE_LIMIT_API', 60),
        'auth' => env('RATE_LIMIT_AUTH', 5),
        'orders' => env('RATE_LIMIT_ORDERS', 10),
    ],

];
