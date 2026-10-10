/**
 * handle.watch(path, fn) (component/handle.js): code outside the view told when a value changes,
 * whatever changed it (the browser, the server's answer, a reset), once the component has
 * rendered the change. A watcher keeps the value it last saw (as JSON: plain data), so it is
 * called only for a value it has not seen, and not for one its own code set (seen(): by the
 * handle that watches; another handle's watchers of the property are told).
 */
export class Watchers {
    constructor(component) {
        this.component = component;
        this.list = new Set();
    }

    /** read() gives the watched value (plain data); owner, the handle watching. Returns a function that stops watching. */
    add(root, read, fn, owner) {
        const watcher = { root, read, fn, owner, last: key(read()) };
        this.list.add(watcher);
        return () => this.list.delete(watcher);
    }

    /** The values under this property are known to the handle that set them. */
    seen(root, owner) {
        for (const watcher of this.list) {
            if (watcher.root === root && watcher.owner === owner) watcher.last = key(watcher.read());
        }
    }

    /** After a render of these properties ("*": any): each watcher of one whose value is new is called. */
    changed(dirty) {
        for (const watcher of [...this.list]) {
            if (!dirty.has('*') && !dirty.has(watcher.root)) continue;
            const value = watcher.read();
            const seen = key(value);
            if (seen === watcher.last) continue;
            watcher.last = seen;
            try {
                watcher.fn(value);
            } catch (error) {
                this.component.runtime.report(this.component, error);
            }
        }
    }

    clear() {
        this.list.clear();
    }
}

function key(value) {
    return JSON.stringify(value ?? null);
}
