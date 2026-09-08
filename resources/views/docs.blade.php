<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} — API Documentation</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui.min.css">
    <style>
        body { margin: 0; background: #fafafa; }
        .topbar { display: none; }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui-bundle.min.js"></script>
    <script>
        window.ui = SwaggerUIBundle({
            {{-- The query string changes whenever the spec does, so an edited
                 spec is never served from the browser cache. --}}
            url: '{{ route('docs.openapi') }}?v={{ filemtime(base_path('docs/openapi.yaml')) }}',
            dom_id: '#swagger-ui',
            deepLinking: true,
            persistAuthorization: true,
            tryItOutEnabled: true,
            defaultModelsExpandDepth: -1,
            presets: [SwaggerUIBundle.presets.apis],
        });
    </script>
</body>
</html>
