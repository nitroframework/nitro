<section class="panels">
    <div class="current">
        <fusion:dynamic-component :is="$panel" />
    </div>
    <button type="button" class="to-search" fusion:click="$set('panel', 'search-box')">Search</button>
    <button type="button" class="to-card" fusion:click="$set('panel', 'card')">Card</button>
    <button type="button" class="to-none" fusion:click="$set('panel', '')">None</button>
    <ul>
        @foreach ($stack as $name)
            <li><fusion:dynamic-component :is="$name" /></li>
        @endforeach
    </ul>
</section>
