import { useState } from 'react';

export default function ReactCounter({ count, doubled, title, increment, save, set, errors }) {
    const [said, setSaid] = useState('');

    return (
        <div className="react-counter">
            <span className="count">{count}</span> <span className="doubled">{doubled}</span>
            <button type="button" className="inc" onClick={() => increment(3)}>+3</button>
            <button type="button" className="local" onClick={() => set('count', count + 1)}>+1 here</button>
            <input className="title" value={title} onChange={(e) => set('title', e.target.value)} />
            <button type="button" className="save" disabled={save.processing} onClick={() => save().then(setSaid, () => setSaid('failed'))}>Save</button>
            {save.processing && <span className="busy">Saving</span>}
            <span className="error">{errors.title?.[0]}</span>
            <span className="said">{said}</span>
        </div>
    );
}
