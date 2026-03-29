<?php

return [
    'key' => 'admin',
    'name' => 'Admin',
    'description' => 'Preset para backoffice com dashboard, listagem de usuários e visão operacional inicial.',
    'entrypoint' => '/admin',
    'focus' => [
        'dashboard',
        'backoffice',
        'internal tools',
        'operational views',
    ],
    'sort' => 30,
];
