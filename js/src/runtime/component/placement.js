/**
 * Components the browser places itself: the children a render placed (kept, given new props, or
 * made), and a component made in place of an element (a child, or a page with its server-made
 * state). As Rendering\Placement does on the server.
 */
import { Component } from './component.js';
import { defaultsOf, readState } from './snapshot.js';
import { ErrorBag } from './errors.js';
import { ATTRIBUTES, MODEL_PROP, selector } from '../protocol.js';

export class Placement {
    constructor(runtime) {
        this.runtime = runtime;
        /** Signed state of the children with mount() or #[Server] methods the server created for the page being rendered, by id. */
        this.provided = new Map();
    }

    /** Run render with the children the server made for it (an answer's, a page's): theirs while it places them. */
    withProvided(children, render) {
        this.provided = new Map(Object.entries(children ?? {}));
        try {
            return render();
        } finally {
            this.provided.clear();
        }
    }

    /**
     * After a render: the children it placed. Existing ones keep their state (a child without
     * mount() takes the new props); new ones are created in the browser, which only a component
     * without mount() can be (mount() runs on the server).
     */
    children(parent, specs) {
        const runtime = this.runtime;
        for (const spec of specs) {
            const existing = runtime.instances.get(spec.id);
            const definition = runtime.definitions.get(spec.name);

            if (existing) {
                existing.parent = parent;
                /** The parent rendered its tag again: the slots come from there now. */
                if (spec.slots) existing.setSlots(spec.slots);
                /** fusion:model on its tag: its #[Modelable] property follows the parent's value. */
                if (spec.model && definition?.modelable && MODEL_PROP in spec.props) {
                    existing.model = { parent, path: spec.model };
                    if (existing.data.get(definition.modelable) !== spec.props[MODEL_PROP]) {
                        existing.data.set(definition.modelable, spec.props[MODEL_PROP]);
                        existing.changed(definition.modelable);
                    }
                }
                if (definition && !definition.mount) {
                    for (const [prop, value] of Object.entries(spec.props)) {
                        if (existing.data.get(prop) !== value && prop in definition.props) {
                            existing.data.set(prop, value);
                            existing.serverData.set(prop, value);
                            existing.changed(prop);
                        }
                    }
                }
                continue;
            }

            const placeholder = parent.scan(selector('placeholder')).find((el) => el.getAttribute(ATTRIBUTES.id) === spec.id);
            if (!placeholder) continue;

            /** A #[LazyLoad] child: made once its chunk is here (its placeholder waits for it). */
            const waiting = definition ? null : runtime.require([spec.name]);
            if (waiting) {
                waiting.then(() => this.children(parent, [spec]), (error) => runtime.report(parent, error));
                continue;
            }

            if (!definition) {
                runtime.report(parent, new Error(`Fusion: component [${spec.name}] is not compiled`));
                continue;
            }

            /** Created by the server for the page being rendered (withProvided()), with its signed state: one with mount() or #[Server] methods. */
            const envelope = this.provided.get(spec.id);
            const provided = envelope ? readState(envelope) : null;
            if (provided && provided.id === spec.id && provided.name === spec.name) {
                this.provided.delete(spec.id);
                this.create(placeholder, definition, provided, envelope, {}, spec.slots, { parent, model: spec.model ? { parent, path: spec.model } : null });
                continue;
            }

            /** A page rendered before its data: the server makes the child when the data comes. */
            if (parent.early && (definition.mount || definition.server.length > 0)) continue;

            if (definition.mount) {
                runtime.report(parent, new Error(`Fusion: [${spec.name}] has mount(), which runs on the server: it cannot be created in the browser. Render it on the server, or pass its data as properties instead of mount() parameters.`));
                continue;
            }

            const data = defaultsOf(definition);
            for (const [name, value] of Object.entries(spec.props)) {
                if (name === MODEL_PROP) {
                    if (!definition.modelable) {
                        runtime.report(parent, new Error(`Fusion: fusion:model on <fusion:${spec.name}>: it has no #[Modelable] property`));
                        continue;
                    }
                    data.set(definition.modelable, value);
                    continue;
                }
                if (!(name in definition.props)) {
                    runtime.report(parent, new Error(`Fusion: [${spec.name}] has no property $${name}`));
                    continue;
                }
                data.set(name, value);
            }

            this.create(placeholder, definition, { id: spec.id, name: spec.name, data, errors: new ErrorBag() }, null, {}, spec.slots, { parent, model: spec.model ? { parent, path: spec.model } : null });
        }
    }

    /**
     * A component the browser renders itself, in place of the element given: a child, or a page
     * (with its server-made state). Its render places its children, made after it is in the page.
     */
    create(placeholder, definition, state, envelope, attributes = {}, slots = {}, links = {}) {
        const runtime = this.runtime;
        const component = new Component(runtime, definition, placeholder, state.id, state.name, state.data, envelope, state.errors);
        component.slots = slots ?? {};
        component.parent = links.parent ?? null;
        component.model = links.model ?? null;
        /** A markup-first page rendered before its data (PageMount.renderEarly()). */
        component.early = links.early ?? false;
        component.deferred = new Set(state.deferred ?? []);
        component.scroll = new Map(state.scroll ?? []);
        const made = component.create((root) => {
            root.setAttribute(ATTRIBUTES.id, state.id);
            root.setAttribute(ATTRIBUTES.name, state.name);
            for (const [name, value] of Object.entries(attributes)) root.setAttribute(name, value);
            placeholder.replaceWith(root);
            runtime.instances.set(state.id, component);
        });
        if (!made) return null;
        runtime.live([component]);
        return component;
    }
}
