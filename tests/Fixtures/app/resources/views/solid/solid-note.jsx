import { useFusion } from 'nitro-fusion/solid';

function Email() {
    const fusion = useFusion();

    return (
        <>
            <input class="email" value={fusion.form.email} onInput={(e) => fusion.set('form.email', e.currentTarget.value)} />
            <span class="email-shown">{fusion.form.email}</span>
        </>
    );
}

export default function SolidNote() {
    const fusion = useFusion();

    return (
        <div class="solid-note">
            <input class="text" value={fusion.text} onInput={(e) => fusion.set('text', e.currentTarget.value)} /> <span class="shown">{fusion.text}</span>
            <button type="button" class="shout" onClick={() => fusion.shout()}>Shout</button>
            <Email />
            <button type="button" class="tag" onClick={() => fusion.addTag('b')}>Tag</button> <span class="tags">{fusion.form.tags.join(',')}</span>
        </div>
    );
}
