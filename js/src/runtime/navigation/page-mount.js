/**
 * A nitro:navigate visit's page, mounted in place of the current one: from the data the server
 * sent (mountPage()), or, for a markup-first page, rendered at once before its data comes
 * (renderEarly()), which then arrives (arrive()).
 */
import { defaultsOf, readState } from '../component/snapshot.js';
import { ErrorBag, takeErrors } from '../component/errors.js';
import { ATTRIBUTES, SIGNALS } from '../protocol.js';

export class PageMount {
    constructor(runtime) {
        this.runtime = runtime;
    }

    /**
     * The page the server described (PageResponses::pageData()) in place of the current one.
     * False when it cannot be done here (the page loads normally then).
     */
    mountPage(current, page) {
        const runtime = this.runtime;
        let state;
        try {
            state = readState(page.snapshot);
        } catch {
            return false;
        }
        const definition = runtime.definitions.get(state.name);
        if (!definition) return false;

        return runtime.placement.withProvided(page.children, () => {
            runtime.forget(current);
            return runtime.placement.create(current, definition, state, page.snapshot, { [ATTRIBUTES.page]: page.layout }) !== null;
        });
    }

    /**
     * A markup-first visit: the page rendered at once in place of the current one, with its
     * properties' defaults, each counting as deferred until the data arrives (arrive()). Null when
     * it cannot render without its data; the visit then goes on as a data-first one.
     */
    renderEarly(current, name, definition, props = {}) {
        const runtime = this.runtime;
        /** A client page (#[Navigate('client')]) needs nothing from the server: it is complete as it renders. */
        const client = Boolean(definition.client);
        const data = defaultsOf(definition);
        /** A client page's route parameters, from the URL, in the properties of the same name. */
        for (const [prop, value] of Object.entries(props)) data.set(prop, value);
        const id = 'f' + Math.random().toString(36).slice(2, 12).padEnd(10, '0');
        const state = { id, name, data, errors: new ErrorBag(), deferred: client ? [] : Object.keys(definition.props) };
        const placeholder = runtime.document.createElement('div');
        current.replaceWith(placeholder);
        runtime.forget(current);
        const component = runtime.placement.create(placeholder, definition, state, null, { [ATTRIBUTES.page]: definition.markup }, {}, { early: !client });
        if (component === null) {
            placeholder.replaceWith(current);
            return null;
        }
        if (client) return component;
        component.sent = new Map(component.data);
        /** Its #[Server] calls wait for its signed state. */
        component.arrival = new Promise((resolve) => (component.arrived = resolve));
        runtime.network.queues.set(component, component.arrival);
        return component;
    }

    /**
     * The data of a page rendered early: its id, signed state, deferred properties and the
     * children only the server could make. What changed here meanwhile (typed, toggled) is kept.
     */
    arrive(component, page) {
        const runtime = this.runtime;
        const state = readState(page.snapshot);
        runtime.instances.delete(component.id);
        component.id = state.id;
        component.el.setAttribute(ATTRIBUTES.id, state.id);
        runtime.instances.set(state.id, component);
        component.early = false;
        takeErrors(component, state.errors);
        component.deferred = new Set(state.deferred);
        component.setScroll(state.scroll, true);
        component.dirty.add(SIGNALS.deferred);
        component.dirty.add(SIGNALS.errors);
        runtime.placement.withProvided(page.children, () => component.applyReload(page.snapshot, [], component.sent ?? new Map()));
        runtime.loadDeferred(component);
        component.arrived?.();
    }
}
