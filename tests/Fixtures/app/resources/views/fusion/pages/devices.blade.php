<div class="devices">
    <button class="copy" fusion:click="copy">Copy</button>
    <button class="paste" fusion:click="paste">Paste</button>
    <p class="copied">{{ $copied ? 'copied' : 'not copied' }}</p>
    <p class="pasted">{{ $pasted }}</p>

    <button class="save" fusion:click="save">Save</button>
    <button class="load" fusion:click="load">Load</button>
    <button class="forget" fusion:click="Fusion::browser()->storage()->forget('draft')">Forget</button>
    <p class="stored">{{ $stored ? 'stored' : 'not stored' }}</p>
    <p class="loaded">{{ $loaded }}</p>

    <canvas fusion:ref="chart" fusion:init="draw"></canvas>
    <button class="grow" fusion:click="grow">Grow</button>

    <button class="connect" fusion:click="connect">Connect</button>
    <button class="say" fusion:click="say">Say</button>
    <button class="disconnect" fusion:click="disconnect">Disconnect</button>
    <p class="open">{{ $open ? 'open' : 'closed' }}</p>
    <ul class="messages">
        @foreach ($messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
</div>
