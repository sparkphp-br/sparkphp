<?php

get(function () {
    return view('starter/admin/audit', [
        'events' => [
            ['time' => '09:03', 'label' => 'queue.retry executado', 'copy' => 'Fila email reprocessada sem falhas.'],
            ['time' => '09:18', 'label' => 'deploy concluído', 'copy' => 'Release 0.9.x publicada no ambiente staging.'],
            ['time' => '10:05', 'label' => 'api:spec atualizada', 'copy' => 'Contrato OpenAPI gerado para o gateway interno.'],
        ],
    ]);
});
