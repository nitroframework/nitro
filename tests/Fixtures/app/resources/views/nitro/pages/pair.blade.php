<div class="pair">
    <p class="maybe">{{ $maybe['name'] ?? 'gone' }}</p>
    <p class="sure">{{ $sure['name'] }}</p>
    <p class="mentee">{{ $mentee?->name }}: {{ $mentee?->mentor?->name ?? 'no mentor' }}</p>
    <ul class="everyone">
        @foreach ($everyone ?? [] as $student)
            <li>{{ $student->name }} ({{ $student->mentor->name ?? '-' }}, {{ $student->mentor?->email }})</li>
        @endforeach
    </ul>
    <button class="ping" nitro:click="ping">Ping</button>
</div>
