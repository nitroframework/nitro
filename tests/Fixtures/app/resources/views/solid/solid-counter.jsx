import { createSignal, Show } from 'solid-js';

export default function SolidCounter(props) {
    const [said, setSaid] = createSignal('');

    return (
        <div class="solid-counter">
            <span class="count">{props.count}</span> <span class="doubled">{props.doubled}</span>
            <button type="button" class="inc" onClick={() => props.increment(3)}>+3</button>
            <button type="button" class="local" onClick={() => props.set('count', props.count + 1)}>+1 here</button>
            <input class="title" value={props.title} onInput={(e) => props.set('title', e.currentTarget.value)} />
            <button type="button" class="save" disabled={props.save.processing} onClick={() => props.save().then(setSaid, () => setSaid('failed'))}>Save</button>
            <Show when={props.save.processing}><span class="busy">Saving</span></Show>
            <span class="error">{props.errors.title?.[0]}</span>
            <span class="said">{said()}</span>
        </div>
    );
}
