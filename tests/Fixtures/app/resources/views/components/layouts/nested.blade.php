{{-- A layout inside the app's: its own aside, and where its pages go. --}}
<x-layouts.app :title="$title ?? null">
    <div class="nested-shell">
        <aside class="nested-side">Nested aside</aside>
        <section fusion:layout="nested">{{ $slot }}</section>
    </div>
</x-layouts.app>
