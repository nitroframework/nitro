import { useFusion } from 'nitro-fusion/react';

function Email() {
    const { form, set } = useFusion();

    return (
        <>
            <input className="email" value={form.email} onChange={(e) => set('form.email', e.target.value)} />
            <span className="email-shown">{form.email}</span>
        </>
    );
}

export default function ReactNote() {
    const { text, form, shout, addTag, set } = useFusion();

    return (
        <div className="react-note">
            <input className="text" value={text} onChange={(e) => set('text', e.target.value)} /> <span className="shown">{text}</span>
            <button type="button" className="shout" onClick={shout}>Shout</button>
            <Email />
            <button type="button" className="tag" onClick={() => addTag('b')}>Tag</button> <span className="tags">{form.tags.join(',')}</span>
        </div>
    );
}
