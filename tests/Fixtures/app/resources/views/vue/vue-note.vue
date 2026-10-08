<template>
    <div class="vue-note">
        <input class="text" v-model="text"> <span class="shown">{{ text }}</span>
        <button type="button" class="shout" @click="shout">Shout</button>
        <input class="email" v-model="form.email"> <span class="email-shown">{{ form.email }}</span>
        <button type="button" class="tag" @click="addTag('b')">Tag</button> <span class="tags">{{ form.tags.join(',') }}</span>
    </div>
</template>
