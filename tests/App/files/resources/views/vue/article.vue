<script setup>
import { useNitro } from 'nitro/vue';

const { topic, body, choose } = useNitro();
</script>

<template>
    <main>
        <button class="topic" @click="choose('laravel')">{{ topic }}</button>
        <article v-html="body" />
    </main>
</template>
