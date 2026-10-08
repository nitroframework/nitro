export default function ReactTalk({ engine, heard, shout, announce, whisper, dispatch }) {
    return (
        <div className="talk">
            <button type="button" className="shout" onClick={() => shout()}>Shout</button>
            <button type="button" className="announce" onClick={() => announce()}>Announce</button>
            <button type="button" className="whisper" onClick={() => whisper()}>Whisper</button>
            <button type="button" className="view-dispatch" onClick={() => dispatch('shouted', `${engine} view`)}>From the view</button>
            <span className="heard">{heard.join('; ')}</span>
        </div>
    );
}
