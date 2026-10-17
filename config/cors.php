<?php

return [
    //se elimino token CSRF
    'paths' => [
        'api/*'
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://127.0.0.1',
        'http://localhost'
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];