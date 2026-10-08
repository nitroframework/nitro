<!DOCTYPE html>
<html>
<head>
    <title>{{ $title ?? 'Fusion' }}</title>
    <script>
        /** The application's own fusion:tooltip (fusion.attributes), registered by a script that runs before the runtime. */
        window.directiveLog = [];
        document.addEventListener('fusion:starting', ({ detail: { Fusion } }) => {
            Fusion.directive('tooltip', ({ el, value, modifiers }) => {
                el.dataset.tip = value + (modifiers.length ? ' @' + modifiers.join('.') : '');
                window.directiveLog.push('run ' + value);
                return () => {
                    window.directiveLog.push('cleanup ' + value);
                    delete el.dataset.tip;
                };
            });
        });
    </script>
    @if (request()->is('engines', 'parity', 'talk'))
        {{-- Vue and the fixture's Vue views (fusionVue() registers them on fusion:starting): only the page that has them. --}}
        <script src="/_test/vue.js"></script>
        <script src="/_test/react.js"></script>
        <script src="/_test/solid.js"></script>
        <script src="/_test/svelte.js"></script>
    @endif
    <script>
        /** The "plain" view engine (fusion.views): views in plain JavaScript, inlined here. */
        {!! file_get_contents(resource_path('views/plain/plain-counter.js')) !!}
        document.addEventListener('fusion:starting', ({ detail: { Fusion } }) => {
            Fusion.engine('plain', {
                load: (view) => Promise.resolve(window.plainViews[view]),
                mount: (module, el, fusion) => module.mount(el, fusion),
            });
        });
    </script>
</head>
<body>
    <nav>
        <a href="/counter" fusion:navigate fusion:current="active">Counter</a>
        <a href="/board" fusion:navigate fusion:current="active" class="board-link">Board</a>
        <a href="/counter" fusion:navigate.keypress class="press-link">Counter (on press)</a>
        <a href="/board" fusion:navigate.prefetch class="prefetch-link">Board (prefetched)</a>
    </nav>
    <main>{{ $slot }}</main>
    <div id="toasts">@portal('toasts')</div>
    <aside id="dock">@portal('dock')</aside>
    @fusionScripts
</body>
</html>
