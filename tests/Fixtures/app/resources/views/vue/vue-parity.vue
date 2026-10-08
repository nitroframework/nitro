<template>
    <div class="parity">
        <span class="count">{{ count }}</span> <span class="doubled">{{ doubled }}</span>
        <button type="button" class="inc" @click="increment(3)">+3</button>
        <input class="title" v-model="title">
        <input class="email" v-model="form.email">
        <span class="email-shown">{{ form.email }}</span>
        <span class="tags">{{ form.tags.join(',') }}</span>
        <button type="button" class="tag" @click="addTag('b')">Tag</button>
        <button type="button" class="save" :disabled="save.processing" @click="save">Save</button>
        <span v-if="save.processing" class="busy">Saving</span>
        <span v-if="save.slow" class="slow">Still saving</span>
        <span v-if="unsaved.length" class="unsaved">Unsaved changes</span>
        <span class="error">{{ errors.title?.[0] }}</span>
        <span class="said">{{ said }}</span>
        <input type="file" class="photo" @change="upload('photo', $event.target.files)">
        <input type="file" class="photos" multiple @change="upload('photos', $event.target.files)">
        <span class="photo-name">{{ photo?.getClientOriginalName() }}</span>
        <span class="photos-count">{{ photos.length }}</span>
        <a class="away" v-bind="link('/counter', 'prefetch')">Away</a>
        <span class="photo-error">{{ errors.photo?.[0] }}</span>
        <span class="photo-progress">{{ uploads.photo?.progress }}</span>
    </div>
</template>
