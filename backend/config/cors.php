<?php

/*
| CORS. En producción la SPA y la API se sirven desde el mismo dominio (nginx), por lo que
| CORS solo aplica en desarrollo o si el frontend se aloja en otro origen (FRONTEND_URL).
*/

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:5173')))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN', 'X-Tenant-ID', 'Authorization', 'Accept'],
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 3600,
    'supports_credentials' => true,
];
