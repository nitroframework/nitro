import { useState } from 'react';

export default function ReactPage({ greeting, knocks, later, knock, shout }) {
    const [shouted, setShouted] = useState('');

    return (
        <section className="react-page">
            <h1 className="greeting">{greeting}</h1>
            <span className="knocks">{knocks}</span>
            <button type="button" className="knock" onClick={() => knock()}>Knock</button>
            <button type="button" className="shout" onClick={() => shout().then(setShouted)}>Shout</button>
            <span className="shouted">{shouted}</span>
            <span className="later">{later ?? 'Loading'}</span>
        </section>
    );
}
