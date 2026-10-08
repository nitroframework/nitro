<?php

return [
    'name' => 'Fusion Tests',
    'env' => 'testing',
    'debug' => true,
    'url' => 'http://localhost',
    'key' => 'base64:'.base64_encode(str_repeat('k', 32)),
    'cipher' => 'AES-256-CBC',
];
