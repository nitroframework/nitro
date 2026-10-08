<div class="search-box">
    <input type="text" class="search" fusion:model="value">
    <button type="button" class="clear" fusion:click="clear">Clear</button>
    <button type="button" class="parent-add" fusion:click="$parent->add()">Add to the shelf</button>
    <button type="button" class="announce" fusion:click="announce">Announce</button>
    <button type="button" class="ping" fusion:click="ping">Ping</button>
    <button type="button" class="server" fusion:click="announceFromServer">From the server</button>
    <span class="pings">{{ $pings }}</span>
</div>
