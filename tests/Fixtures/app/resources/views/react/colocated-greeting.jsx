/* <php>
use Illuminate\Support\Str;

new class {
    #[Validate('required|min:2')]
    public string $name = 'ada';

    #[Computed]
    public function shout(): string
    {
        return Str::upper($this->name).'!';
    }

    #[Server]
    public function greet(): string
    {
        $this->validate();

        return 'Hello, '.Str::title($this->name).' (from the server)';
    }
};
</php> */
import { useState } from 'react';

/** A component written in its React view: the PHP above is its class (no extends: Component). */
export default function ColocatedGreeting({ name, shout, set, greet, errors }) {
    const [said, setSaid] = useState('');

    return (
        <div className="colocated-greeting">
            <input className="name" value={name} onChange={(e) => set('name', e.target.value)} />
            <span className="shout">{shout}</span>
            <button type="button" className="greet" onClick={() => greet().then(setSaid, () => setSaid(''))}>Greet</button>
            <span className="said">{said}</span>
            <span className="error">{errors.name?.[0]}</span>
        </div>
    );
}
