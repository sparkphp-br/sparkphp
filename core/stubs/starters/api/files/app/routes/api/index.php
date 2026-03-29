<?php

get(fn() => [
    'name' => env('APP_NAME', 'SparkPHP API'),
    'starter' => 'api',
    'version' => spark_version(),
    'release' => spark_release_line(),
    'status' => 'ok',
    'entrypoint' => '/api',
    'endpoints' => [
        ['method' => 'GET', 'path' => '/api', 'description' => 'API entrypoint'],
        ['method' => 'GET', 'path' => '/api/health', 'description' => 'Health check'],
        ['method' => 'GET', 'path' => '/api/customers', 'description' => 'List sample customers'],
        ['method' => 'POST', 'path' => '/api/customers', 'description' => 'Create a sample customer payload'],
    ],
    'generated_at' => now()->format(DATE_ATOM),
]);
