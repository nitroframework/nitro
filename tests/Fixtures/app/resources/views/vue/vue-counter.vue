<script setup>
import { ref } from 'vue';
import { useFusion } from 'nitro-fusion/vue';

const { count, doubled, increment, save, errors } = useFusion();
const said = ref('');

async function submit() {
    said.value = await save().catch(() => 'failed');
}
</script>

<template>
    <div class="vue-counter">
        <span class="count">{{ count }}</span> <span class="doubled">{{ doubled }}</span>
        <button type="button" class="inc" @click="increment(3)">+3</button>
        <button type="button" class="local" @click="count++">+1 here</button>
        <input class="title" v-model="title">
        <button type="button" class="save" :disabled="save.processing" @click="submit">Save</button>
        <span v-if="save.processing" class="busy">Saving</span>
        <span class="error">{{ errors.title?.[0] }}</span>
        <span class="said">{{ said }}</span>
    </div>
</template>
