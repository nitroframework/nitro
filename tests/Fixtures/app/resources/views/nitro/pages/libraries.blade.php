<div class="libraries">
    <p class="values">{{ $category }}|{{ implode(',', $tags) }}|{{ $day }}|{{ $color }}|{{ $size }}|{{ $fake }}|{{ implode(',', array_column($rows, 'pick')) }}|{{ $clicks }}</p>

    <button type="button" class="bump" nitro:click="$set('clicks', $clicks + 1)">Bump</button>
    <button type="button" class="local-reset" nitro:click="localReset">Reset here</button>
    <button type="button" class="server-reset" nitro:click="serverReset">Reset on the server</button>
    <button type="button" class="toggle" nitro:click="$toggle('show')">Toggle</button>
    <button type="button" class="add-row" nitro:click="addRow">Add a row</button>

    {{-- Each library owns its element's markup: nitro:ignore keeps a render out of it. --}}
    <div nitro:ignore>
        <select class="s2" nitro:select2="category">
            <option value="a">A</option>
            <option value="b">B</option>
            <option value="c">C</option>
        </select>
    </div>
    <div nitro:ignore>
        <select class="s2-multi" multiple nitro:select2="tags">
            <option value="x">X</option>
            <option value="y">Y</option>
            <option value="z">Z</option>
        </select>
    </div>
    <div nitro:ignore>
        <input class="fp" nitro:flatpickr="day">
    </div>
    <div nitro:ignore>
        <select class="ts" nitro:tom-select="color">
            <option value="red">Red</option>
            <option value="green">Green</option>
            <option value="blue">Blue</option>
        </select>
    </div>
    <div nitro:ignore>
        <select class="ch" nitro:choices="size">
            <option value="s">S</option>
            <option value="m">M</option>
            <option value="l">L</option>
        </select>
    </div>
    <div nitro:ignore>
        <div class="fake" nitro:fake-widget="fake"></div>
    </div>

    <p class="more">{{ strip_tags($body) }}|{{ strip_tags($note) }}|{{ implode('', $order) }}|{{ $volume }}|{{ $card }}</p>
    <div nitro:ignore>
        <div class="trix" nitro:trix="body"></div>
    </div>
    <div nitro:ignore>
        <div class="quill" nitro:quill="note"></div>
    </div>
    <div nitro:ignore>
        <ul class="sortable" nitro:sortable="order"></ul>
    </div>
    <div nitro:ignore>
        <div class="slider" nitro:slider="volume"></div>
    </div>
    <div nitro:ignore>
        <input class="masked" nitro:mask="card">
    </div>

    {{-- A component whose view has a @nitroScript: one here, one in the @if. --}}
    <nitro:sparkline />

    @if ($show)
        <nitro:sparkline />
        <div class="shown" nitro:ignore>
            <select class="s2-if" nitro:select2="category">
                <option value="a">A</option>
                <option value="b">B</option>
                <option value="c">C</option>
            </select>
        </div>
    @endif

    @foreach ($rows as $i => $row)
        <div class="row" nitro:key="row-{{ $i }}" nitro:ignore>
            <select class="s2-row" nitro:select2="rows.{{ $i }}.pick">
                <option value="a">A</option>
                <option value="b">B</option>
                <option value="c">C</option>
            </select>
        </div>
    @endforeach

    <a class="away" href="/counter" nitro:navigate>Away</a>
</div>
