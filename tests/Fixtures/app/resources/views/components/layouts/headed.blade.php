<!DOCTYPE html>
<html>
<head>
    @nitroHead
    @if (request()->is('article', 'vue-page', 'react-page', 'solid-page', 'svelte-page'))
        {{-- The frameworks and the fixture's views, for the pages whose own view is one (and the page they are visited from). --}}
        <script src="/_test/vue.js"></script>
        <script src="/_test/react.js"></script>
        <script src="/_test/solid.js"></script>
        <script src="/_test/svelte.js"></script>
    @endif
</head>
<body>
    <main>{{ $slot }}</main>
    @nitroScripts
</body>
</html>
