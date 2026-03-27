<?php

// GET /api/health — public health check
get(fn() => [
    'status'    => 'ok',
    'timestamp' => date('c'),
    'php'       => PHP_VERSION,
    'env'       => env('APP_ENV'),
]);
