/**
 * A component is let go when its element leaves the document, whoever took it out (ARCHITECTURE.md,
 * "What Nitro shares"). Nitro's own renders and visits forget what they remove at once; this
 * catches the rest: a script closing a dialog, a library replacing its markup. It looks after the
 * removal's task, so an element moved elsewhere (taken out, then put back) is in the document
 * again and stays live.
 */
export class Removals {
    constructor(runtime, window) {
        this.runtime = runtime;
        this.window = window;
        this.observer = null;
    }

    /** Watching the whole document, once. */
    watch() {
        if (this.observer || typeof this.window.MutationObserver !== 'function') return;
        this.observer = new this.window.MutationObserver((records) => this.removed(records));
        this.observer.observe(this.window.document, { childList: true, subtree: true });
    }

    removed(records) {
        /** The window closed: its page is gone as a whole, not its components one by one. */
        if (!this.window.document) return;
        const gone = [];
        for (const record of records) {
            for (const node of record.removedNodes) {
                if (node.nodeType === 1 && !node.isConnected) gone.push(node);
            }
        }
        if (gone.length === 0) return;
        for (const component of this.runtime.components()) {
            if (component.el.isConnected) continue;
            if (gone.some((node) => node === component.el || node.contains(component.el))) this.runtime.forget(component.el);
        }
    }
}
