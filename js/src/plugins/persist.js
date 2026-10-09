/**
 * #[Persist] (a store's, or its properties'): their values kept in localStorage, so they outlast a
 * reload. When the store is made in a tab, the values kept are set as the browser's own changes
 * (the server checks them on the next call, as any change); each change is kept again. Without
 * localStorage (private modes, blocked storage), the store simply starts from the server's state.
 *
 * A plugin, built on the plugin API only (runtime/plugins.js).
 */
export function persist({ on }) {
    on('store', (store) => {
        if (store.persist.length === 0) return;
        const storage = storageOf();
        if (!storage) return;
        const key = `nitro:persist:${store.name}`;

        let kept = null;
        try {
            kept = JSON.parse(storage.getItem(key) ?? 'null');
        } catch {
            kept = null;
        }

        if (kept !== null && typeof kept === 'object' && !Array.isArray(kept)) {
            for (const property of store.persist) {
                if (!(property in kept)) continue;
                try {
                    store.set(property, kept[property]);
                } catch {
                    /** A property the store no longer lets the browser change: its kept value is dropped. */
                }
            }
        }

        const keep = () => {
            try {
                storage.setItem(key, JSON.stringify(Object.fromEntries(store.persist.map((property) => [property, store.get(property)]))));
            } catch {
                /** Full or blocked: the values stay this tab's only. */
            }
        };
        keep();
        store.onChange(keep);
    });
}

function storageOf() {
    try {
        return window.localStorage ?? null;
    } catch {
        return null;
    }
}
