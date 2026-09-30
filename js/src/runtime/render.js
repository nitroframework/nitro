/**
 * A component's view: its compiled template (Fusion.define's template, expressions and subs)
 * rendered as Viewi renders its viewi.json (runtime/view, ported from Viewi's core/render,
 * core/hydrate, core/anchor and core/reactivity).
 *
 *  - hydrate(el): the server's HTML taken over, node by node (Viewi's hydration)
 *  - create(): a new root element (a child made in the browser, a page from fusion:navigate)
 *  - update(names): the properties that changed; what reads them renders (Viewi's $$r)
 *
 * A component renders one root element. A block around it (an @if at the top of the view) is
 * no block of its own: when what it reads changes, the component makes its root again.
 */
import { evaluate } from './view/call.js';
import { cleanup } from './view/hydrate.js';
import { directiveCodes, refreshSlots, renderTag, teleported } from './view/render.js';
import { conditionOf } from './view/renderIf.js';
import { createScope, dispose, keysOf, rootScope, run } from './view/scope.js';

/** The component's root element, built anew (nothing tracked): the template parity test's. */
export function render(definition, $this, host, document) {
    return new View(definition, $this, host, document, false).create();
}

export class View {
    /**
     * @param {object} host  on(el, event, modifiers, fn) registers a handler; child(name, key,
     *                       props, slots) returns the child's id (null leaves it out); slot(name)
     *                       the parent's content for a slot; added(node) and removed(node):
     *                       elements a render puts in the page and takes out; id: the component's
     */
    constructor(definition, $this, host, document, tracking = true) {
        /** Viewi's component instance: its expressions ($$t) and what reads what ($$r). */
        this.instance = {
            $this,
            $$t: definition.expressions,
            subs: definition.subs ?? [],
            $$r: new Map(),
            host,
            document,
            ownerId: host.id,
            anchors: null,
            leaks: new WeakMap(),
            rootSubs: new Set(),
            /** The slot regions in its view, rendered again when the parent's slots change. */
            slotRegions: new Set(),
            /** Fusion: where its @teleport content went */
            teleports: new Set(),
        };
        this.template = definition.template.h ?? [];
        this.tracking = tracking;
        this.scope = null;
    }

    /** The server's HTML under el, taken over: el is the root element. */
    hydrate(el) {
        const [node, scope] = this.root();
        this.instance.anchors = new Map();
        try {
            renderTag(null, this.instance, node, scope, true, false, el);
            cleanup(this.instance);
        } finally {
            this.instance.anchors = null;
        }
    }

    /** A new root element. */
    create() {
        const [node, scope] = this.root();
        const fragment = this.instance.document.createDocumentFragment();
        return renderTag(fragment, this.instance, node, scope, false, false);
    }

    /** The parent passed new slots: the slot regions render them. */
    refreshSlots() {
        refreshSlots(this.instance);
    }

    /** The top elements of its @teleport content, wherever they went. */
    teleported() {
        return teleported(this.instance);
    }

    /** Let go of what it tracks (its component left the page): Viewi's dispose of the root scope. */
    dispose() {
        if (this.scope) dispose(this.instance, this.scope);
        this.scope = null;
        this.instance.$$r = new Map();
    }

    /** Render what reads the properties that changed ("*": all). False when the root must be made again. */
    update(names) {
        for (const name of names) {
            if (name === '*' ? this.instance.rootSubs.size > 0 : this.instance.rootSubs.has(name) || this.instance.rootSubs.has('*')) return false;
        }
        run(this.instance, names);
        return true;
    }

    /**
     * The root tag, and the scope it renders in: the top of the view is its one element, maybe
     * in an @if chain (whose conditions the root reads).
     */
    root() {
        if (this.scope) dispose(this.instance, this.scope);
        this.instance.$$r = new Map();
        this.instance.rootSubs = new Set();
        this.scope = rootScope();
        this.scope.untracked = !this.tracking;

        let found = null;
        const visit = (nodes, scope) => {
            for (let i = 0; i < nodes.length; i++) {
                const node = nodes[i];
                if (node.t === 'm') continue;
                if (node.t === 'x') {
                    const text = node.e ? evaluate(this.instance, node.code, scope) : node.c ?? '';
                    if (String(text).trim() !== '') throw rootError();
                    continue;
                }
                if (node.t === 't' && found === null) {
                    found = [node, scope];
                    continue;
                }
                if (node.t !== 'template' || !['if', 'else-if', 'else', 'error'].includes(node.i[0].c)) throw rootError();

                /** An @if chain: its taken branch holds the root. */
                let last = i;
                while (['else-if', 'else'].includes(nodes[last + 1]?.i?.[0]?.c)) last++;
                const chain = nodes.slice(i, last + 1);
                i = last;
                for (const key of keysOf(this.instance, chain.flatMap((n) => directiveCodes(n)))) this.instance.rootSubs.add(key);
                for (const branch of chain) {
                    const condition = conditionOf(this.instance, branch.i[0], scope);
                    if (!condition.value) continue;
                    visit(branch.h ?? [], condition.args === scope.arguments ? scope : createScope(scope, branch.i[0].c, condition.args));
                    break;
                }
            }
        };
        visit(this.template, this.scope);

        if (found === null) throw rootError();
        return found;
    }
}

function rootError() {
    return new Error('A Fusion component must render a single root element.');
}
