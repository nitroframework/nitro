<?php

return [
    /** In memory for PHPUnit; files when the app is served for browser tests. */
    'driver' => getenv('NITRO_TEST_DB') ? 'file' : 'array',
    'files' => dirname(__DIR__).'/storage/framework/sessions',
    'lifetime' => 120,
    'cookie' => 'nitro_test_session',
];
