<?php

get(fn() => [
    'data' => [
        ['id' => 1, 'name' => 'Acme Corp', 'plan' => 'growth', 'status' => 'active'],
        ['id' => 2, 'name' => 'Northwind', 'plan' => 'starter', 'status' => 'trial'],
    ],
    'meta' => [
        'starter' => 'api',
        'count' => 2,
    ],
]);

post(function () {
    $data = validate([
        'name' => 'required|string|min:3',
        'email' => 'required|email',
        'plan' => 'required|in:starter,growth,scale',
    ]);

    return created([
        'data' => [
            'id' => 101,
            'name' => $data['name'],
            'email' => $data['email'],
            'plan' => $data['plan'],
            'status' => 'pending',
        ],
        'meta' => [
            'starter' => 'api',
            'message' => 'Payload accepted by the API starter.',
        ],
    ]);
});
