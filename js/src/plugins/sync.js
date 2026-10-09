/**
 * #[Sync] (a store's, or its properties'): their values shared with the site's other tabs, through
 * a BroadcastChannel per store. A change in one tab is set in the others as their own browser
 * change (the server checks it on their next call); a tab does not echo back what it was sent.
 * A tab opened later starts from the server's state (with #[Persist] too: from the kept values).
 *
 * A plugin, built on the plugin API only (runtime/plugins.js).
 */
export function sync({ on }) {
    on('store', (store) => {
        if (store.sync.length === 0 || typeof window.BroadcastChannel !== 'function') return;
        const channel = new window.BroadcastChannel(`nitro:sync:${store.name}`);
        const values = () => JSON.stringify(Object.fromEntries(store.sync.map((property) => [property, store.get(property)])));
        let last = values();

        store.onChange(() => {
            const now = values();
            if (now === last) return;
            last = now;
            channel.postMessage(now);
        });

        channel.onmessage = (event) => {
            let received;
            try {
                received = JSON.parse(event.data);
            } catch {
                return;
            }
            if (received === null || typeof received !== 'object') return;
            last = event.data;
            for (const property of store.sync) {
                if (!(property in received)) continue;
                try {
                    store.set(property, received[property]);
                } catch {
                    /** A property this tab's store does not let the browser change (another deploy): left as it is. */
                }
            }
        };
    });
}
