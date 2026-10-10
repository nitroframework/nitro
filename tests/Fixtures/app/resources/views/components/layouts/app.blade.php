<!DOCTYPE html>
<html>
<head>
    <title>{{ $title ?? 'Nitro' }}</title>
    <script>
        /** The application's own nitro:tooltip (nitro.attributes), registered by a script that runs before the runtime. */
        window.directiveLog = [];
        document.addEventListener('nitro:starting', ({ detail: { Nitro } }) => {
            Nitro.directive('tooltip', ({ el, value, modifiers }) => {
                el.dataset.tip = value + (modifiers.length ? ' @' + modifiers.join('.') : '');
                window.directiveLog.push('run ' + value);
                return () => {
                    window.directiveLog.push('cleanup ' + value);
                    delete el.dataset.tip;
                };
            });
        });
    </script>
    @if (request()->is('libraries*'))
        {{-- Third-party libraries, and the directives that bind them (resources/js/libraries.js): only the pages that have them. --}}
        @foreach (['jquery', 'select2', 'flatpickr', 'tom-select', 'choices', 'trix', 'quill', 'sortable', 'nouislider', 'imask', 'libraries'] as $library)
            <script src="/_test/lib/{{ $library }}.js"></script>
        @endforeach
    @endif
    @if (request()->is('engines', 'parity', 'talk'))
        {{-- Vue and the fixture's Vue views (nitroVue() registers them on nitro:starting): only the page that has them. --}}
        <script src="/_test/vue.js"></script>
        <script src="/_test/react.js"></script>
        <script src="/_test/solid.js"></script>
        <script src="/_test/svelte.js"></script>
    @endif
    <script>
        /** The "plain" view engine (nitro.views): views in plain JavaScript, inlined here. */
        {!! file_get_contents(resource_path('views/plain/plain-counter.js')) !!}
        document.addEventListener('nitro:starting', ({ detail: { Nitro } }) => {
            Nitro.engine('plain', {
                load: (view) => Promise.resolve(window.plainViews[view]),
                mount: (module, el, nitro) => module.mount(el, nitro),
            });
        });
    </script>
</head>
<body>
    <nav>
        <a href="/counter" nitro:navigate nitro:current="active">Counter</a>
        <a href="/board" nitro:navigate nitro:current="active" class="board-link">Board</a>
        <a href="/counter" nitro:navigate.keypress class="press-link">Counter (on press)</a>
        <a href="/board" nitro:navigate nitro:prefetch class="prefetch-link">Board (prefetched)</a>
        <a href="/nested" nitro:navigate class="nested-link">Nested</a>
        <a href="/journal" nitro:navigate nitro:prefetch.visible class="visible-link">Journal (prefetched in view)</a>
    </nav>
    <main nitro:layout="app">{{ $slot }}</main>
    <div id="toasts">@portal('toasts')</div>
    <aside id="dock">@portal('dock')</aside>
    @nitroScripts
</body>
</html>
