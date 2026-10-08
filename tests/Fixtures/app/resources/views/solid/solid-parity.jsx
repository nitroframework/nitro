import { Show } from 'solid-js';

export default function SolidParity(props) {
    return (
        <div class="parity">
            <span class="count">{props.count}</span> <span class="doubled">{props.doubled}</span>
            <button type="button" class="inc" onClick={() => props.increment(3)}>+3</button>
            <input class="title" value={props.title} onInput={(e) => props.set('title', e.currentTarget.value)} />
            <input class="email" value={props.form.email} onInput={(e) => props.set('form.email', e.currentTarget.value)} />
            <span class="email-shown">{props.form.email}</span>
            <span class="tags">{props.form.tags.join(',')}</span>
            <button type="button" class="tag" onClick={() => props.addTag('b')}>Tag</button>
            <button type="button" class="save" disabled={props.save.processing} onClick={props.save}>Save</button>
            <Show when={props.save.processing}><span class="busy">Saving</span></Show>
            <Show when={props.save.slow}><span class="slow">Still saving</span></Show>
            <Show when={props.unsaved.length}><span class="unsaved">Unsaved changes</span></Show>
            <span class="error">{props.errors.title?.[0]}</span>
            <span class="said">{props.said}</span>
            <input type="file" class="photo" onChange={(e) => props.upload('photo', e.currentTarget.files)} />
            <input type="file" class="photos" multiple onChange={(e) => props.upload('photos', e.currentTarget.files)} />
            <span class="photo-name">{props.photo?.getClientOriginalName()}</span>
            <span class="photos-count">{props.photos.length}</span>
            <a class="away" {...props.link('/counter', 'prefetch')}>Away</a>
            <span class="photo-error">{props.errors.photo?.[0]}</span>
            <span class="photo-progress">{props.uploads.photo?.progress}</span>
        </div>
    );
}
