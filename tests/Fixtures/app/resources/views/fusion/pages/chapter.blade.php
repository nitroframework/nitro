<section class="chapter">
    <h1 class="number">Chapter {{ $number }}</h1>
    <p class="part">{{ $part ?? 'whole' }}</p>
    <button class="read" fusion:click="markRead">{{ $read ? 'Read' : 'Mark read' }}</button>
    <a class="next" href="/chapters/{{ $number + 1 }}" fusion:navigate>Next</a>
</section>
