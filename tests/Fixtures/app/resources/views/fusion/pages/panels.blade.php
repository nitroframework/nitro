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
    <div class="stamps">
        @foreach ($rows as $row)
            <fusion:stamp :label="$row" :key="'stamp-'.$row" />
        @endforeach
    </div>
    <button type="button" class="add-row" fusion:click="addRow">Add a row</button>
    <div class="badges">
        @foreach ($badges as $i => $label)
            <fusion:badge :label="$label" :key="'badge-'.$i" />
        @endforeach
    </div>
    <button type="button" class="add-badge" fusion:click="addBadge">Add a badge</button>
    <input class="keys" fusion:keydown.enter="$set('key', 'enter')" fusion:keydown.escape="$set('key', 'escape')">
    <span class="key">{{ $key }}</span>
</section>
