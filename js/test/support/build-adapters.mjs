// The framework adapters as the browser tests load them, each with the fixture app's views for it,
// registered as an app registers them, in one script per framework (js/test/e2e/.built/):
//   vue.js     the .vue views compiled with Vue's own SFC compiler; uses the global Vue
//              (vue.global.js, served before it)
//   react.js   React, its adapter and the .jsx views (JSX compiled by esbuild)
//   solid.js   Solid, its adapter and the .jsx views (JSX compiled by Solid's Babel preset, as
//              vite-plugin-solid compiles it), registered with lazy() (nitro/lazy)
//   svelte.js  Svelte, its adapter (a .svelte.js module: runes) and the .svelte views, compiled by
//              Svelte's own compiler, as vite-plugin-svelte compiles them
//   ssr.mjs    the render service with every framework's views, compiled for the server
// stdin: anything; stdout: [] (Node::run's protocol), or the build's errors.
import * as esbuild from 'esbuild';
import { transformAsync } from '@babel/core';
import solidPreset from 'babel-preset-solid';
import { createHash } from 'node:crypto';
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { compileScript, compileTemplate, parse } from 'vue/compiler-sfc';
import { compile as compileSvelte, compileModule as compileSvelteModule } from 'svelte/compiler';

const root = process.cwd();
const fixtureViews = join(root, 'tests/Fixtures/app/resources/views');

/** 'nitro/vue', /react, /solid, /svelte: the adapters, as an app's Vite alias names them. */
const aliases = {
    name: 'aliases',
    setup(build) {
        build.onResolve({ filter: /^nitro\/(vue|react|solid)$/ }, ({ path }) => ({ path: join(root, 'js/adapters', `${path.split('/')[1]}.js`) }));
        build.onResolve({ filter: /^nitro\/svelte$/ }, () => ({ path: join(root, 'js/adapters/svelte.svelte.js') }));
    },
};

/**
 * Svelte's .svelte views and .svelte.js modules (runes), compiled by Svelte's own compiler for the
 * DOM or for the server. A view's <php> block is left out, as Nitro's Vite plugin does.
 */
const svelte = (generate = 'client') => ({
    name: 'svelte',
    setup(build) {
        build.onLoad({ filter: /\.svelte$/ }, ({ path }) => {
            const source = readFileSync(path, 'utf8').replace(/<php>[\s\S]*?<\/php>/, (block) => block.replace(/[^\n]/g, ''));
            return { contents: compileSvelte(source, { filename: path, generate }).js.code, loader: 'js', resolveDir: join(fixtureViews, 'svelte') };
        });
        build.onLoad({ filter: /\.svelte\.js$/ }, ({ path }) => ({
            contents: compileSvelteModule(readFileSync(path, 'utf8'), { filename: path, generate }).js.code,
            loader: 'js',
            resolveDir: join(root, 'js/adapters'),
        }));
    },
});

/** 'vue' is the page's global Vue. */
const globalVue = {
    name: 'global-vue',
    setup(build) {
        build.onResolve({ filter: /^vue$/ }, () => ({ path: 'vue', namespace: 'global-vue' }));
        build.onLoad({ filter: /.*/, namespace: 'global-vue' }, () => ({ contents: 'module.exports = window.Vue;', loader: 'js' }));
    },
};

/**
 * A .vue file as Vite's Vue plugin compiles one: <script setup> with its template inline, or a
 * template alone. `ssr`: for the render service (the template renders to a string).
 */
const sfc = (ssr = false) => ({
    name: 'vue-sfc',
    setup(build) {
        build.onLoad({ filter: /\.vue$/ }, ({ path }) => {
            /** The <php> block left out, as Nitro's Vite plugin does. */
            const source = readFileSync(path, 'utf8').replace(/<php>[\s\S]*?<\/php>/, (block) => block.replace(/[^\n]/g, ''));
            const { descriptor, errors } = parse(source, { filename: path });
            if (errors.length) return { errors: errors.map((e) => ({ text: String(e.message ?? e) })) };
            const id = createHash('sha256').update(path).digest('hex').slice(0, 8);
            const resolveDir = join(fixtureViews, 'vue');

            if (descriptor.scriptSetup || descriptor.script) {
                return { contents: compileScript(descriptor, { id, inlineTemplate: true, templateOptions: { ssr, ssrCssVars: [] } }).content, loader: 'js', resolveDir };
            }

            const template = compileTemplate({ source: descriptor.template.content, filename: path, id, ssr, ssrCssVars: [] });
            return { contents: `${template.code}\nexport default { ${ssr ? 'ssrRender' : 'render'} };`, loader: 'js', resolveDir };
        });
    },
});

/**
 * Solid's .jsx: compiled by its Babel preset (fine-grained DOM updates, not React's
 * createElement), hydratable: for the DOM in the browser, for a string in the render service.
 */
const solidJsx = (generate = 'dom') => ({
    name: 'solid-jsx',
    setup(build) {
        build.onLoad({ filter: /views[\\/]solid[\\/].*\.jsx$/ }, async ({ path }) => {
            const result = await transformAsync(readFileSync(path, 'utf8'), { filename: path, presets: [[solidPreset, { generate, hydratable: true }]] });
            return { contents: result.code, loader: 'js', resolveDir: join(fixtureViews, 'solid') };
        });
    },
});

/** The render service with the fixture's views, as an app's SSR entry serves them (resources/js/ssr.js). */
function serviceEntry() {
    const views = (directory, extension) => readdirSync(directory).filter((file) => file.endsWith(extension)).map((file) => join(directory, file));
    const engines = { vue: ['renderVue', views(join(fixtureViews, 'vue'), '.vue')], react: ['renderReact', views(join(fixtureViews, 'react'), '.jsx')], solid: ['renderSolid', views(join(fixtureViews, 'solid'), '.jsx')], svelte: ['renderSvelte', views(join(fixtureViews, 'svelte'), '.svelte')] };
    const lines = [`import { serve } from ${JSON.stringify(join(root, 'js/adapters/server/serve.js'))};`];
    const served = [];

    for (const [engine, [renderer, files]] of Object.entries(engines)) {
        lines.push(`import { ${renderer} } from ${JSON.stringify(join(root, `js/adapters/server/${engine}.js`))};`);
        files.forEach((file, i) => lines.push(`import * as ${engine}${i} from ${JSON.stringify(file)};`));
        served.push(`${engine}: ${renderer}({ ${files.map((file, i) => `${JSON.stringify(`./${relative(join(fixtureViews, engine), file)}`)}: ${engine}${i}`).join(', ')} })`);
    }

    lines.push(`serve({ ${served.join(', ')} });`);
    return lines.join('\n');
}

/** An adapter and the fixture's views for it, registered as an app registers them: modules, as import.meta.glob(..., { eager: true }) gives them. */
function entryFor(adapter, register, directory, extension) {
    const files = readdirSync(directory).filter((file) => file.endsWith(extension));
    return [
        `import { ${register} } from ${JSON.stringify(join(root, adapter))};`,
        ...files.map((file, i) => `import * as view${i} from ${JSON.stringify(join(directory, file))};`),
        `${register}(${views(files)});`,
    ].join('\n');
}

/** The same, registered with lazy() (nitro/lazy): the adapter imported with the first view that loads. */
function lazyEntryFor(name, directory, extension) {
    const files = readdirSync(directory).filter((file) => file.endsWith(extension));
    return [
        `import { lazy } from ${JSON.stringify(join(root, 'js/adapters/lazy.js'))};`,
        ...files.map((file, i) => `import * as view${i} from ${JSON.stringify(join(directory, file))};`),
        `lazy('${name}', () => import('nitro/${name}'), ${views(files)});`,
    ].join('\n');
}

/** The views as import.meta.glob() gives them, by their paths. */
function views(files) {
    return `{ ${files.map((file, i) => `${JSON.stringify(`./${file}`)}: view${i}`).join(', ')} }`;
}

const browser = { format: 'iife', platform: 'browser', target: ['es2020'], conditions: ['browser'] };
/** Node, as `vite build --ssr` makes it: the frameworks from node_modules, resolved for the server. */
const service = { format: 'esm', platform: 'node', target: ['node20'], packages: 'external' };

const builds = [
    { name: 'vue.js', entry: entryFor('js/adapters/vue.js', 'nitroVue', join(fixtureViews, 'vue'), '.vue'), plugins: [aliases, globalVue, sfc()], ...browser },
    { name: 'react.js', entry: entryFor('js/adapters/react.js', 'nitroReact', join(fixtureViews, 'react'), '.jsx'), plugins: [aliases], ...browser },
    /** Solid through lazy(), so the browser tests run a lazily loaded framework against the others. */
    { name: 'solid.js', entry: lazyEntryFor('solid', join(fixtureViews, 'solid'), '.jsx'), plugins: [aliases, solidJsx('dom')], ...browser },
    { name: 'svelte.js', entry: entryFor('js/adapters/svelte.svelte.js', 'nitroSvelte', join(fixtureViews, 'svelte'), '.svelte'), plugins: [aliases, svelte('client')], ...browser },
    { name: 'ssr.mjs', entry: serviceEntry(), plugins: [aliases, sfc(true), solidJsx('ssr'), svelte('server')], ...service },
];

try {
    for (const { name, entry, plugins, ...target } of builds) {
        await esbuild.build({
            stdin: { contents: entry, resolveDir: root, loader: 'js' },
            bundle: true,
            jsx: 'automatic',
            loader: { '.jsx': 'jsx' },
            define: { 'process.env.NODE_ENV': '"production"' },
            outfile: join(root, `js/test/e2e/.built/${name}`),
            plugins,
            logLevel: 'silent',
            ...target,
        });
    }
    process.stdout.write(JSON.stringify([]));
} catch (error) {
    process.stdout.write(JSON.stringify((error.errors ?? [{ text: String(error) }]).map((e) => `${relative(root, e.location?.file ?? '')} ${e.text}`)));
}
