<?php

get(function () {
    return view('starter/saas/dashboard', [
        'stats' => [
            ['label' => 'Receita ativa', 'value' => 'R$ 48,2k'],
            ['label' => 'Tickets abertos', 'value' => '7'],
            ['label' => 'Latência média', 'value' => '84ms'],
        ],
        'tasks' => [
            'Conectar autenticação real em app/routes/[auth]/',
            'Substituir cards de exemplo pelos seus KPIs',
            'Ligar billing, webhooks e policies da aplicação',
        ],
    ]);
});
