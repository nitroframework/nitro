<div class="devices">
    <button class="copy" nitro:click="copy">Copy</button>
    <button class="paste" nitro:click="paste">Paste</button>
    <p class="copied">{{ $copied ? 'copied' : 'not copied' }}</p>
    <p class="pasted">{{ $pasted }}</p>

    <button class="save" nitro:click="save">Save</button>
    <button class="load" nitro:click="load">Load</button>
    <button class="forget" nitro:click="Nitro::browser()->storage()->forget('draft')">Forget</button>
    <p class="stored">{{ $stored ? 'stored' : 'not stored' }}</p>
    <p class="loaded">{{ $loaded }}</p>

    <canvas nitro:ref="chart" nitro:init="draw"></canvas>
    <button class="grow" nitro:click="grow">Grow</button>

    <button class="connect" nitro:click="connect">Connect</button>
    <button class="say" nitro:click="say">Say</button>
    <button class="disconnect" nitro:click="disconnect">Disconnect</button>
    <p class="open">{{ $open ? 'open' : 'closed' }}</p>
    <ul class="messages">
        @foreach ($messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
</div>
