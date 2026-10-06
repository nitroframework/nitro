/**
 * #[LazyLoad] chunks: which chunk each component is in, the pages that render them (a visit loads
 * the page's chunk while it fetches the page's data), loading a chunk once; and the markup-first
 * pages a visit renders at once. A part of the runtime.
 */
export class Chunks {
    constructor(runtime) {
        this.runtime = runtime;
        /** component => chunk */
        this.components = new Map();
        /** [path pattern, chunk] of the pages */
        this.routes = [];
        /** chunk => its load */
        this.loads = new Map();
        /** [path pattern, page] of the markup-first pages */
        this.markup = [];
    }

    /** From app.js: the #[LazyLoad] components (name => chunk) and the pages that render them ([path pattern, chunk]). */
    lazy(components, routes) {
        this.components = new Map(Object.entries(components));
        this.routes = routes.map(([pattern, group]) => [new RegExp(pattern), group]);
    }

    /** From app.js: the markup-first pages ([path pattern, page]). */
    markupPages(routes) {
        this.markup = routes.map(([pattern, name]) => [new RegExp(pattern), name]);
    }

    /** The markup-first page a path shows, or null. */
    markupPage(path) {
        return this.markup.find(([pattern]) => pattern.test(path))?.[1] ?? null;
    }

    /** A chunk's components: loaded once, from where @fusionScripts says chunks are (data-chunks). */
    load(group) {
        const { runtime } = this;
        /** Its components are here already (the page loaded the chunk with its own script tag). */
        const inGroup = [...this.components].filter(([, chunk]) => chunk === group).map(([name]) => name);
        if (!this.loads.has(group) && inGroup.length > 0 && inGroup.every((name) => runtime.definitions.has(name))) {
            this.loads.set(group, Promise.resolve());
        }
        if (!this.loads.has(group)) {
            this.loads.set(group, new Promise((resolve, reject) => {
                const script = runtime.document.createElement('script');
                const pattern = runtime.config.chunks ?? `${runtime.config.endpoint}/app/{id}.js?v=${encodeURIComponent(runtime.config.bundle ?? '')}`;
                script.src = pattern.replace('{id}', encodeURIComponent(group));
                script.onload = () => resolve();
                script.onerror = () => {
                    this.loads.delete(group);
                    /** Gone with a deploy since the page loaded (a build's files are replaced): the page loads again. */
                    runtime.stale();
                    reject(new Error(`Fusion: the components of chunk [${group}] did not load`));
                };
                runtime.document.head.appendChild(script);
            }));
        }
        return this.loads.get(group);
    }

    /** The chunks the components need that are not here yet: a promise, or null when none are missing. */
    require(names) {
        const groups = new Set();
        for (const name of names) {
            if (!this.runtime.definitions.has(name) && this.components.has(name)) groups.add(this.components.get(name));
        }
        return groups.size ? Promise.all([...groups].map((group) => this.load(group))) : null;
    }

    /** A visit to the path: start loading the chunk of the page it renders, while its data loads. */
    preload(path) {
        for (const [pattern, group] of this.routes) {
            if (pattern.test(path)) this.load(group).catch(() => {});
        }
    }
}
