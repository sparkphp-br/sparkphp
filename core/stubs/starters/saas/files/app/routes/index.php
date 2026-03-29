<?php

get(function () {
    return view('starter/saas/home', [
        'metrics' => [
            ['label' => 'MRR', 'value' => 'R$ 48,2k'],
            ['label' => 'Trials ativos', 'value' => '128'],
            ['label' => 'NPS', 'value' => '71'],
        ],
        'highlights' => [
            ['title' => 'Onboarding curto', 'copy' => 'Equipe ou cliente entram no ar em minutos com file-based routing e setup previsivel.'],
            ['title' => 'Operacao observavel', 'copy' => 'Inspector, benchmarks e filas versionadas ja fazem parte do produto.'],
            ['title' => 'AI pronta no core', 'copy' => 'Texto, embeddings, agents e retrieval seguem a mesma API curta do framework.'],
        ],
    ]);
});
