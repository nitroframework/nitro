export default function SolidTalk(props) {
    return (
        <div class="talk">
            <button type="button" class="shout" onClick={() => props.shout()}>Shout</button>
            <button type="button" class="announce" onClick={() => props.announce()}>Announce</button>
            <button type="button" class="whisper" onClick={() => props.whisper()}>Whisper</button>
            <button type="button" class="view-dispatch" onClick={() => props.dispatch('shouted', `${props.engine} view`)}>From the view</button>
            <span class="heard">{props.heard.join('; ')}</span>
        </div>
    );
}
