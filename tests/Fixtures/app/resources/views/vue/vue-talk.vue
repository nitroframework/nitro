<template>
    <div class="talk">
        <button type="button" class="shout" @click="shout()">Shout</button>
        <button type="button" class="announce" @click="announce()">Announce</button>
        <button type="button" class="whisper" @click="whisper()">Whisper</button>
        <button type="button" class="view-dispatch" @click="dispatch('shouted', `${engine} view`)">From the view</button>
        <span class="heard">{{ heard.join('; ') }}</span>
    </div>
</template>
