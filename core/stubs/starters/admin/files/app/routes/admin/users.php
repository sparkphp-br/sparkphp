<?php

get(function () {
    return view('starter/admin/users', [
        'users' => [
            ['name' => 'Ana Silva', 'email' => 'ana@example.com', 'role' => 'owner', 'status' => 'active'],
            ['name' => 'Bruno Costa', 'email' => 'bruno@example.com', 'role' => 'support', 'status' => 'invited'],
            ['name' => 'Clara Lima', 'email' => 'clara@example.com', 'role' => 'finance', 'status' => 'active'],
        ],
    ]);
});
