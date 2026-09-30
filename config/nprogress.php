<?php

return [

    /*
    |--------------------------------------------------------------------------
    | NProgress
    |--------------------------------------------------------------------------
    |
    | The progress bar shown while a fusion:navigate visit loads the next page
    | (NProgress, https://ricostacruz.com/nprogress). @fusionScripts passes
    | these settings to the browser.
    |
    */

    // Show the bar at all.
    'show' => env('NPROGRESS_SHOW', true),

    // Also while a #[Server] call runs (not only on navigation).
    'server_calls' => env('NPROGRESS_SERVER_CALLS', false),

    // Wait this long (ms) before showing it. A visit faster than this shows no bar; with 0 every
    // visit starts one, and NProgress holds each step `speed` ms, so a quick page is already there
    // while the bar still shows its first step on its own.
    'delay' => 30,

    /*
    |--------------------------------------------------------------------------
    | Look
    |--------------------------------------------------------------------------
    |
    | The bar spans the page as it progresses: `height` is its thickness. Sizes
    | are CSS lengths.
    |
    */

    'color' => env('NPROGRESS_COLOR', '#29d'),

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
    | Motion (NProgress's own options)
    |--------------------------------------------------------------------------
    |
    | minimum: where the bar starts (0 to 1). speed: its animation, in ms.
    | trickle: creep forward while waiting, every trickle_speed ms.
    |
    */

    'minimum' => 0.08,

    'easing' => 'ease',

    'speed' => 200,

    'trickle' => true,

    'trickle_speed' => 200,

];
