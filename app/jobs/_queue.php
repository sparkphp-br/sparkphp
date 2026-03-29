<?php

return [
    'defaults' => [
        'tries' => 3,
        'backoff' => [60, 120, 300],
        'timeout' => 0,
        'fail_on_timeout' => false,
    ],

    'routes' => [
        // SendEmailJob::class => [
        //     'queue' => 'mail',
        //     'tries' => 5,
        //     'backoff' => [10, 30, 90],
        //     'timeout' => 30,
        //     'fail_on_timeout' => true,
        // ],
    ],
];
