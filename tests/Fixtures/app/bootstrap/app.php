<?php

/** What `optimize` / `route:cache` boot: the framework picked by FUSION_FRAMEWORK. */
return require __DIR__.'/'.(getenv('FUSION_FRAMEWORK') ?: 'laravel').'.php';
