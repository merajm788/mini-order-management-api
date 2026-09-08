<?php

use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/docs'));

/*
|--------------------------------------------------------------------------
| API documentation
|--------------------------------------------------------------------------
|
| Swagger UI is loaded from a CDN and pointed at the checked-in OpenAPI
| document, so the project needs no documentation package or build step.
|
*/

Route::get('/docs', fn () => view('docs'))->name('docs');

Route::get('/docs/openapi.yaml', function () {
    $path = base_path('docs/openapi.yaml');

    abort_unless(file_exists($path), 404);

    // no-store, or the browser keeps serving a stale spec after the file changes.
    return Response::file($path, [
        'Content-Type' => 'application/yaml',
        'Cache-Control' => 'no-store',
    ]);
})->name('docs.openapi');
