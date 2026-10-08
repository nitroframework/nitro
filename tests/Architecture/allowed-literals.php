<?php

/**
 * Literals spelled across several parts of Fusion that are not concepts without a home, each
 * with why. A literal goes here only with a reason that holds; when unsure, it goes in
 * known-breaks.php to be reviewed, not here.
 */
$language = 'a word of PHP or JavaScript themselves';
$web = 'a word of HTTP, HTML or the DOM, not Fusion\'s choosing';
$generic = 'a common word that means different things where it is used (checked)';
$modifier = 'a modifier: Compiler/Globals.php is the list the compiler checks; the runtime acts on it';
$engine = 'each adapter names its own engine; vite.js names the modules';
$directive = 'a Fusion Blade directive: Compiler/Globals::DIRECTIVES lists them; CompiledFormatTest holds the provider (server) and the compiler (browser) to it';
$uses = 'a kind of what compiled code uses: Compiler/Uses::KINDS declares them, and the shape every part indexes';
$global = $uses.'; and a browser global (js/protocol.json globals: CompiledFormatTest holds Scripts and the runtime to it)';
$compiled = 'the compiled format\'s code: js/protocol.json lists it, CompiledFormatTest holds both sides to it';
$snapshot = 'a snapshot\'s field (js/protocol.json snapshot: CompiledFormatTest holds Snapshot and the runtime to it)';
$answer = 'an answer\'s field (js/protocol.json answer: CompiledFormatTest holds UpdateController, PageResponses and the runtime to it)';
$method = 'a component method Fusion provides (Compiler/Globals::COMPONENT_METHODS)';

return [
    'array' => $language, 'bool' => $language, 'boolean' => $language, 'float' => $language, 'int' => $language,
    'null' => $language, 'number' => $language, 'object' => $language, 'string' => $language, 'function' => $language,
    'true' => $language, 'false' => $language, 'Error' => $language, '...' => $language, 'self' => $language,
    'static' => $language, 'strval' => $language, 'trim' => $language, 'json' => $language, 'php' => $language,
    'mixed' => $language,
    'echo' => $generic.': Laravel Echo, a Blade echo token, a broadcast prefix',

    'Content-Type' => $web, 'POST' => $web, 'application/json' => $web, 'UTF-8' => $web, 'sha256' => $web.' (the hash algorithm)',
    'input' => $web, 'textarea' => $web, 'script' => $web, 'style' => $web, 'meta' => $web, 'title' => $web, 'img' => $web, 'pre' => $web, 'div' => $web.' (a JavaScript view\'s wrapper is js/protocol.json\'s view.wrapper)',
    'html' => $web, 'class' => $web, 'id' => $web, 'value' => $web, 'name' => $web, 'type' => $web,
    'click' => $web, 'change' => $web, 'blur' => $web, 'focusin' => $web,

    './core.js' => 'a module import', '../php.js' => 'a module import',

    'default' => $generic, 'data' => $generic, 'key' => $generic, 'path' => $generic, 'url' => $generic, 'uri' => $generic,
    'request' => $generic, 'component' => $generic, 'store' => $generic, 'attributes' => $generic, 'files' => $generic,
    'message' => $generic, 'line' => $generic, 'node' => $generic, 'kind' => $generic, 'method' => $generic,
    'property' => $generic, 'to' => $generic, 'when' => $generic, 'root' => $generic, 'except' => $generic,
    'hydrate' => $generic.': a hook, fusion:hydrate (an island\'s), and a tag\'s option',
    'fusion' => $generic.': the config key, the adapters\' context symbol, a JSON key',
    'only' => $generic.': a header\'s role (Protocol::header()), the attribute bag\'s ->only()',
    'version' => $generic.': a header\'s role (Protocol::header()), the bundle\'s version',
    'runtime' => $generic.': a path\'s role (Protocol::path()), the build summary\'s runtime (Bundle::summary()\'s shape, read by fusion:build)',
    'bundle' => $generic.': a path\'s role (Protocol::path()), the render service\'s bundle (fusion.ssr.bundle)',
    'upload' => $generic.': a path\'s role (Protocol::path()), a property\'s upload',
    'directive' => $generic.': an attribute\'s role (Protocol::attribute()), a Blade directive token',
    'compiled' => $generic.': the protocol\'s compiled format, the compiled files\' folder (fusion.compiled)',
    'destroyed' => 'a hook\'s name: the runtime names the hook it calls (HomesTest\'s hook rule watches where)',

    'debounce' => $modifier, 'once' => $modifier.'; also the once data, reviewed with 17.13', 'prefetch' => $modifier,
    'delay' => $modifier, 'duration' => $modifier, 'origin' => $modifier, 'out-in' => $modifier, 'in-out' => $modifier,
    'remove' => $modifier, 'attr' => $modifier, 'loading' => $modifier, 'poll' => $modifier, 'visible' => $modifier,
    'navigate' => $modifier, 'init' => $modifier.' (a trigger: Globals::TRIGGERS)',
    '$reload' => 'a handler action ($reload: Globals::ACTIONS): what plugins hear a reload of the page\'s properties as',
    '$refresh' => 'a handler action ($refresh: Globals::ACTIONS): what a call with no method is named',
    'fusion:model' => 'an attribute the developer writes: Globals.php checks it, the runtime acts on it',
    'fusion:model.' => 'an attribute the developer writes (with modifiers): Globals.php checks it, the runtime acts on it',
    'fusion:target' => 'an attribute the developer writes: Globals.php checks it, the runtime acts on it',

    'teleport' => $directive, 'endteleport' => $directive, 'enddeferred' => $directive, 'placeholder' => $directive.'; also fusion:placeholder (Protocol::attribute())',

    'routes' => $global, 'lang' => $global, 'config' => $global.'; also Laravel\'s config service', 'functions' => $uses.'; also names.json\'s key', 'classes' => $uses,

    'state' => $snapshot.' (an envelope\'s); also the page data block\'s role (Protocol::attribute())',
    'memo' => $snapshot, 'nextPage' => $snapshot.' (the scroll position)', 'route' => $snapshot.'; also Laravel\'s route() (Globals::HELPERS)',
    'merge' => $snapshot.'; also Blade\'s $attributes->merge()',
    'slot' => $snapshot.' (slots); also Blade\'s $slot, which a view reads', 'slots' => $snapshot.'; also Blade\'s $slots, which a view reads',
    'errors' => $snapshot.'; also a signal (js/protocol.json), the template\'s $errors (Globals::TEMPLATE_VARIABLES), Laravel\'s validation errors',
    'deferred' => $snapshot.'; also a signal (js/protocol.json) and a Fusion directive (Globals::DIRECTIVES)',
    'scroll' => $snapshot.'; also a signal (js/protocol.json) and the DOM event',
    'js' => $answer.' (an effect); also '.$method, 'redirect' => $answer.' (an effect); also '.$method, 'reload' => $answer.' (an effect); also '.$method.' and a handler action',
    'event' => $answer.' (a dispatch); also the handler\'s $event (Globals::HANDLER_VARIABLES)',
    'shared' => $answer.'; also a browser global (js/protocol.json globals) and '.$method,
    'stores' => $answer.'; also a browser global and a definition field (js/protocol.json)',
    'snapshot' => $answer.'; also an attribute\'s role (Protocol::attribute())',

    'vue' => $engine, 'react' => $engine, 'solid' => $engine, 'svelte' => $engine,

    'if' => $compiled, 'else' => $compiled, 'else-if' => $compiled, 'for' => $compiled, 'foreach' => $compiled,
    'while' => $compiled, 'switch' => $compiled, 'case' => $compiled, 'break' => $compiled.' (and PHP\'s keyword)',
    'empty' => $compiled, 'error' => $compiled, 'template' => $compiled.' (and the HTML element)',
    'page' => $compiled.' (a definition field)', 'view' => $compiled.' (a definition field)',
    'properties' => $compiled.' (a JavaScript view\'s state and names)', 'methods' => $compiled.' (a JavaScript view\'s state and names)',
    'server' => $compiled.' (a JavaScript view\'s state and names)', 'computed' => $compiled.' (a JavaScript view\'s state and names)',
    'engine' => $compiled.' (a definition field, a JavaScript view\'s state)',
    'props' => $compiled.' (a definition field; a child tag\'s values)',
    'enum' => $compiled.' (a prop\'s field); also a property\'s kind (PropertyMeta)',
    'model' => $generic.': fusion:model (Globals), a property\'s kind (PropertyMeta)',
];
