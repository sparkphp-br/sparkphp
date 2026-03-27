<?php

// Throttle Middleware
// Limita o número de requisições por minuto por IP.
// Uso: [throttle:60]/ ou ->guard('throttle:30')

$limit = (int) ($params[0] ?? 60);
$key   = 'throttle:' . ip();

$current = (int) (cache($key) ?? 0);

if ($current >= $limit) {
    return json(['error' => 'Too many requests. Please slow down.'], 429);
}

cache([$key => $current + 1], 60);
