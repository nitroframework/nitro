<div class="search-box">
    <input type="text" class="search" nitro:model="value">
    <button type="button" class="clear" nitro:click="clear">Clear</button>
    <button type="button" class="parent-add" nitro:click="$parent->add()">Add to the shelf</button>
    <button type="button" class="announce" nitro:click="announce">Announce</button>
    <button type="button" class="ping" nitro:click="ping">Ping</button>
    <button type="button" class="server" nitro:click="announceFromServer">From the server</button>
    <span class="pings">{{ $pings }}</span>
</div>
