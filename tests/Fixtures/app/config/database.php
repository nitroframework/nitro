<?php

return [
    'default' => 'sqlite',
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            /** In memory for PHPUnit; a file when the app is served for browser tests. */
            'database' => getenv('NITRO_TEST_DB') ?: ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ],
];
