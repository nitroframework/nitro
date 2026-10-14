/**
 * The $this of a component's compiled code: its properties (an enum property read as its case),
 * its methods, its stores, and Nitro's actions ($dispatch, $reload, $refresh, ...).
 */
import * as P from '../../php.js';
import { slotHasContent } from '../view/render.js';

/**
 * An enum property (public Status $status): the state holds its value, as the server sends it and
 * nitro:model writes it; compiled code reads the case (null for a value that names none).
 */
export function enumCase(runtime, prop, value) {
    return value === null || value === undefined ? null : runtime.classes[prop.enum].tryFrom(value);
}

/** What compiled code assigns to an enum property: a case (stored as its value) or null, as PHP's type allows. */
export function enumValue(prop, name, value) {
    if (value instanceof P.EnumCase) return value.value;
    if (value === null || value === undefined) return null;
    throw new P.PhpError('TypeError', `Cannot assign ${P.typeName(value)} to property $${name}: it takes a case of its enum`);
}

/** An error's field ("form.email") by the browser's name for its property: the definition's names (an obfuscated build), else as it is. */
export function browserField(definition, path) {
    const [root, ...rest] = P.toStr(path).split('.');
    const names = definition.names?.properties ?? {};
    return Object.hasOwn(names, root) ? [names[root], ...rest].join('.') : path;
}

/** Its properties on a $this (a component's or a store's): an enum property read as its case, written as its value. */
export function defineProperties(ctx, holder) {
    for (const [name, prop] of Object.entries(holder.definition.props)) {
        Object.defineProperty(ctx, name, {
            enumerable: true,
            get: () => (prop.enum ? enumCase(holder.runtime, prop, holder.data.get(name)) : holder.data.get(name) ?? null),
            set: (value) => holder.write(name, prop.enum ? enumValue(prop, name, value) : value, prop),
        });
    }
}

/** The $this of compiled code: properties, methods, and Nitro's actions. */
export function contextOf(component) {
    const self = component;
    const ctx = Object.create(null);

    defineProperties(ctx, component);

    for (const [name, fn] of Object.entries(component.definition.methods)) {
        ctx[name] = fn;
    }

    /** public Cart $cart: the tab's store, shared with every component that uses it. */
    for (const [property, store] of Object.entries(component.definition.stores ?? {})) {
        Object.defineProperty(ctx, property, { enumerable: true, get: () => self.runtime.stores.named(store).ctx });
    }

    Object.assign(ctx, {
        $errors: undefined,
        $call: (method, args) => self.runtime.network.call(self, method, args),
        $computed: (name) => self.computed(name),
        $dispatch: (event, params) => self.dispatch(event, params),
        $parent: (method, args) => self.callParent(method, args),
        $reset: (names) => self.reset(names),
        $redirect: (url) => self.runtime.navigator.visit(P.toStr(url)),
        $set: (path, value) => self.setPath(path, value),
        $toggle: (path) => self.setPath(path, !P.bool(self.getPath(path))),
        $refresh: () => self.definition.server.length ? self.runtime.network.call(self, null, []) : self.render(),
        $js: (code) => self.js(code),
        $reload: (names) => self.runtime.reload(self, names),
        $loadMore: (name) => self.runtime.loadMore(self, P.toStr(name)),
        /** $this->hasMore('posts'): whether the scroll prop has a next page. */
        $hasMore: (name) => self.nextPage(P.toStr(name)) !== null,
        /**
         * An error's field known as the page runs ($errors->has($field), @error($field)), by the
         * browser's name for its property (an obfuscated build: the definition's names; else as it is).
         */
        $field: (path) => browserField(component.definition, path),
        /** @deferred: whether any of the properties (any, when none are named) is still loading. */
        $deferring: (names) => (names.length ? names.some((name) => self.deferred.has(name)) : self.deferred.size > 0),
    });
    Object.defineProperty(ctx, '$errors', { get: () => self.errors });
    /** Nitro::browser(): what its APIs (js/functions/browser/_browser_*.js) need of the component. */
    Object.defineProperty(ctx, '$browser', { get: () => self.browser() });
    /** $slots->has('footer') and $slot->isEmpty() in its view. */
    ctx.$slots = { has: (name) => slotHasContent(self.slots[P.toStr(name)]) };

    return ctx;
}
