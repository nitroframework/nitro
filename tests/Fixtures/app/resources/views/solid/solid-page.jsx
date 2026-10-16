import { createSignal } from 'solid-js';

export default function SolidPage(props) {
    const [shouted, setShouted] = createSignal('');

    return (
        <section class="solid-page">
            <h1 class="greeting">{props.greeting}</h1>
            <span class="knocks">{props.knocks}</span>
            <button type="button" class="knock" onClick={() => props.knock()}>Knock</button>
            <button type="button" class="shout" onClick={() => props.shout().then(setShouted)}>Shout</button>
            <span class="shouted">{shouted()}</span>
            <span class="later">{props.later ?? 'Loading'}</span>
        </section>
    );
}
