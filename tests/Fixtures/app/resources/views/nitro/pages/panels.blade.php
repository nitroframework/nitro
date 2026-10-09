<section class="panels">
    <div class="current">
        <nitro:dynamic-component :is="$panel" />
    </div>
    <button type="button" class="to-search" nitro:click="$set('panel', 'search-box')">Search</button>
    <button type="button" class="to-card" nitro:click="$set('panel', 'card')">Card</button>
    <button type="button" class="to-none" nitro:click="$set('panel', '')">None</button>
    <ul>
        @foreach ($stack as $name)
            <li><nitro:dynamic-component :is="$name" /></li>
        @endforeach
    </ul>
    <div class="stamps">
        @foreach ($rows as $row)
            <nitro:stamp :label="$row" :key="'stamp-'.$row" />
        @endforeach
    </div>
    <button type="button" class="add-row" nitro:click="addRow">Add a row</button>
    <div class="badges">
        @foreach ($badges as $i => $label)
            <nitro:badge :label="$label" :key="'badge-'.$i" />
        @endforeach
    </div>
    <button type="button" class="add-badge" nitro:click="addBadge">Add a badge</button>
    <input class="keys" nitro:keydown.enter="$set('key', 'enter')" nitro:keydown.escape="$set('key', 'escape')">
    <span class="key">{{ $key }}</span>
</section>
