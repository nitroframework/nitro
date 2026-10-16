<script setup>
import { ref } from 'vue';
import { useNitro } from 'nitro/vue';

const { greeting, knocks, later, knock, shout } = useNitro();
const shouted = ref('');

async function loud() {
    shouted.value = await shout();
}
</script>

<template>
    <section class="vue-page">
        <h1 class="greeting">{{ greeting }}</h1>
        <span class="knocks">{{ knocks }}</span>
        <button type="button" class="knock" @click="knock()">Knock</button>
        <button type="button" class="shout" @click="loud">Shout</button>
        <span class="shouted">{{ shouted }}</span>
        <span class="later">{{ later ?? 'Loading' }}</span>
    </section>
</template>
