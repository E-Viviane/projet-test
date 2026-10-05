<?php

// CORS : quelles pages web (origines) ont le droit d'appeler cette API depuis un navigateur ?
// Ici : les applications front de développement (Angular :4200, Vue/Vite :5173). En production, mettre le domaine exact du frontend dans .env (CORS_ORIGINS).
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_filter(array_map('trim', explode(',', env('CORS_ORIGINS', 'http://localhost:4200,http://127.0.0.1:4200,http://localhost:5173,http://127.0.0.1:5173')))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'Idempotency-Key', 'If-None-Match', 'X-Request-Id'],
    'exposed_headers' => ['Location', 'ETag', 'Retry-After', 'X-Request-Id', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Idempotent-Replayed'],
    'max_age' => 600,
    'supports_credentials' => false, // jetons Bearer : pas de cookies
];
