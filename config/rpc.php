<?php

return [
    'endpoints' => [
        'service-1'        => env('RPC_SERVICE1_QUEUE', 'service-1-queue'),
        'service-2' => env('RPC_SERVICE2_QUEUE', 'service-2-queue'),
    ],

    'timeout_seconds' => (float) env('RPC_TIMEOUT', 3.0),
    'retries'         => (int) env('RPC_RETRIES', 0),
    'retry_delay_ms'  => (int) env('RPC_RETRY_DELAY_MS', 100),

    'cb' => [
        'failure_threshold' => (int) env('RPC_CB_FAILS', 5),
        'open_seconds'      => (int) env('RPC_CB_OPEN_SEC', 15),
    ],

    'routes' => [
        'service-1' => [
           // 'service-1.create'     => 'MyService@create',
//            'service-1.getProfile' => 'MyService@getProfile',
        ],
        'service-2' => [
            //'service-2.submit'   =>'MyService@submit',
        ],
    ],
];
