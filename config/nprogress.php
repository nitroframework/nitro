<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Progress bar
    |--------------------------------------------------------------------------
    |
    | The bar shown while a fusion:navigate visit loads the next page
    | (nitro-progress). It shows at once, creeps forward while the server
    | answers, and fills and fades as the new page appears. @fusionScripts
    | passes these settings to the browser.
    |
    */

    // Show the bar at all.
    'show' => true,

    // Also while a #[Server] call runs (not only on navigation).
    'server_calls' => false,

    // Wait this long (ms) before showing it: a visit faster than this shows no bar. With 0, every
    // visit shows one, as a quick sweep for a fast page.
    'delay' => 0,

    // Once shown, the bar runs at least this long (ms): a fast visit keeps it moving until then, so
    // it is seen to complete rather than flash. 0: it finishes as soon as the page is there.
    'minimum_duration' => 250,

    /*
    |--------------------------------------------------------------------------
    | Look
    |--------------------------------------------------------------------------
    |
    | Sizes are CSS lengths.
    |
    */

    'color' => '#29d',

    'height' => '3px',

    // Where the bar runs: 'top' or 'bottom' of the window.
    'position' => 'top',

    // The glow at the bar's leading end: its width, and whether to show it.
    'glow' => true,

    'glow_width' => '100px',

    // The spinning circle in the corner, and its width and height.
    'spinner' => false,

    'spinner_size' => '18px',

    'z_index' => 1031,

    /*
    |--------------------------------------------------------------------------
    | Motion
    |--------------------------------------------------------------------------
    |
    | minimum and maximum: where the bar starts, and how far it creeps before
    | the page arrives (0 to 1). trickle: creep every trickle_speed ms. speed
    | and easing: the width animation. fade_speed: the fade at the end (ms).
    |
    */

    'minimum' => 0.15,

    'maximum' => 0.92,

    'trickle' => true,

    'trickle_speed' => 100,

    'speed' => 150,

    'easing' => 'ease-out',

    'fade_speed' => 150,

];
