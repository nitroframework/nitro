import { useNitro } from 'nitro/solid';

function Email() {
    const nitro = useNitro();

    return (
        <>
            <input class="email" value={nitro.form.email} onInput={(e) => nitro.set('form.email', e.currentTarget.value)} />
            <span class="email-shown">{nitro.form.email}</span>
        </>
    );
}

export default function SolidNote() {
    const nitro = useNitro();

    return (
        <div class="solid-note">
            <input class="text" value={nitro.text} onInput={(e) => nitro.set('text', e.currentTarget.value)} /> <span class="shown">{nitro.text}</span>
            <button type="button" class="shout" onClick={() => nitro.shout()}>Shout</button>
            <Email />
            <button type="button" class="tag" onClick={() => nitro.addTag('b')}>Tag</button> <span class="tags">{nitro.form.tags.join(',')}</span>
        </div>
    );
}
