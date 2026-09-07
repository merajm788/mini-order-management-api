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
            url: '{{ route('docs.openapi') }}',
            dom_id: '#swagger-ui',
            deepLinking: true,
            persistAuthorization: true,
            tryItOutEnabled: true,
            presets: [SwaggerUIBundle.presets.apis],
        });
    </script>
</body>
</html>
