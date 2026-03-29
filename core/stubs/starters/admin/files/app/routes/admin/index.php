<?php

get(function () {
    return view('starter/admin/dashboard', [
        'stats' => [
            ['label' => 'Pedidos em revisão', 'value' => '24'],
            ['label' => 'Filas com atraso', 'value' => '2'],
            ['label' => 'Falhas hoje', 'value' => '0'],
        ],
        'links' => [
            ['label' => 'Usuários', 'href' => '/admin/users'],
            ['label' => 'Auditoria', 'href' => '/admin/audit'],
            ['label' => 'Docs internas', 'href' => '/documents'],
        ],
    ]);
});
