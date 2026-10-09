<div class="report">
    <h1>{{ $title }}</h1>
    @deferred('stats')
        <p class="stats">{{ $stats['orders'] }} orders, {{ $stats['total'] }}</p>
    @placeholder
        <p class="loading">Loading stats</p>
    @enddeferred
    @deferred
        <p class="all">Chart: {{ count($chart) }} points</p>
    @enddeferred
    <button class="click" nitro:click="click">{{ $clicks }}</button>
</div>
