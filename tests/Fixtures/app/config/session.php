<?php

return [
    /** In memory for PHPUnit; files when the app is served for browser tests. */
    'driver' => getenv('FUSION_TEST_DB') ? 'file' : 'array',
    'files' => dirname(__DIR__).'/storage/framework/sessions',
    'lifetime' => 120,
    'cookie' => 'fusion_test_session',
];
