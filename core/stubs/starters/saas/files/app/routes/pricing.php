<?php

get(function () {
    return view('starter/saas/pricing', [
        'plans' => [
            ['name' => 'Starter', 'price' => 'R$ 99', 'tag' => 'mensal', 'features' => ['1 workspace', '10 membros', 'OpenAPI e Inspector inclusos']],
            ['name' => 'Growth', 'price' => 'R$ 299', 'tag' => 'mensal', 'features' => ['Workspaces ilimitados', 'Queue file + retry', 'AI observavel por default']],
            ['name' => 'Scale', 'price' => 'Sob consulta', 'tag' => 'anual', 'features' => ['SLA dedicado', 'Single-tenant', 'Suporte de migração']],
        ],
    ]);
});
