<?php

// CORS Middleware

$origin  = $_SERVER['HTTP_ORIGIN'] ?? '*';
$allowed = env('CORS_ORIGIN', '*');

header('Access-Control-Allow-Origin: ' . ($allowed === '*' ? '*' : $origin));
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN');
header('Access-Control-Allow-Credentials: true');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
