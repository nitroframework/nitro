export default function ReactParity({ count, doubled, title, form, said, photo, photos, increment, addTag, save, set, upload, uploads, link, unsaved, errors }) {
    return (
        <div className="parity">
            <span className="count">{count}</span> <span className="doubled">{doubled}</span>
            <button type="button" className="inc" onClick={() => increment(3)}>+3</button>
            <input className="title" value={title} onChange={(e) => set('title', e.target.value)} />
            <input className="email" value={form.email} onChange={(e) => set('form.email', e.target.value)} />
            <span className="email-shown">{form.email}</span>
            <span className="tags">{form.tags.join(',')}</span>
            <button type="button" className="tag" onClick={() => addTag('b')}>Tag</button>
            <button type="button" className="save" disabled={save.processing} onClick={save}>Save</button>
            {save.processing && <span className="busy">Saving</span>}
            {save.slow && <span className="slow">Still saving</span>}
            {unsaved.length > 0 && <span className="unsaved">Unsaved changes</span>}
            <span className="error">{errors.title?.[0]}</span>
            <span className="said">{said}</span>
            <input type="file" className="photo" onChange={(e) => upload('photo', e.target.files)} />
            <input type="file" className="photos" multiple onChange={(e) => upload('photos', e.target.files)} />
            <span className="photo-name">{photo?.getClientOriginalName()}</span>
            <span className="photos-count">{photos.length}</span>
            <a className="away" {...link('/counter', 'prefetch')}>Away</a>
            <span className="photo-error">{errors.photo?.[0]}</span>
            <span className="photo-progress">{uploads.photo?.progress}</span>
        </div>
    );
}
