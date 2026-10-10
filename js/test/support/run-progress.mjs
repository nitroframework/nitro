// The progress bar through visits (stdin: {options, scenarios: {name: [[ms, event], ...]}}): the
// real runtime/navigation/bar.js and progress.js in jsdom, the bar sampled every few ms. stdout: what went
// wrong, by scenario: the bar going back while it is shown, a bar appearing part way (not from
// its start), a bar left on the page; with a minimum duration, a bar gone sooner or before 100%.
import { JSDOM } from 'jsdom';
import { readStdin } from './common.mjs';

const input = await readStdin();
const dom = new JSDOM('<!DOCTYPE html><body></body>', { pretendToBeVisual: true });
Object.assign(globalThis, { window: dom.window, document: dom.window.document });
const { Progress } = await import('../../src/runtime/navigation/progress.js');
const progress = new Progress(dom.window, input.options);

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const failures = {};

/**
 * The bar in #nprogress, the fixed box NProgress fades: a fixed bar outside it (NProgress's own
 * styles) had its part over a sticky sidebar stay while the rest faded.
 */
const style = [...document.querySelectorAll('style')].map((s) => s.textContent).join('');
if (!/#nprogress \{[^}]*position: fixed/.test(style) || !/#nprogress \.bar \{[^}]*position: absolute/.test(style)) {
    failures.styles = ['#nprogress must be the fixed box and the bar absolute in it'];
}

for (const [name, steps] of Object.entries(input.scenarios)) {
    const problems = [];
    let lastEl = null;
    let lastPct = null;
    /** The bar on screen: when it appeared and how far it got (minimumDuration's checks). */
    let since = null;
    let furthest = 0;
    const minimum = Number(input.options.minimumDuration ?? 0);
    /** A bar replaced by the next visit's need not reach 100%: the new visit takes over. */
    const ended = (replaced) => {
        if (since === null || minimum === 0) return;
        const lasted = performance.now() - since;
        if (lasted < minimum) problems.push(`a bar gone after ${lasted.toFixed(0)}ms`);
        if (!replaced && furthest < 99.5) problems.push(`a bar gone at ${furthest.toFixed(0)}%`);
    };
    const sample = setInterval(() => {
        const el = document.getElementById('nprogress');
        const pct = el ? progress.bar.width : null;
        const shown = el && el.style.opacity !== '0';
        if (el !== lastEl) {
            if (lastEl) ended(el !== null);
            since = el ? performance.now() : null;
            furthest = 0;
        }
        if (pct !== null) furthest = Math.max(furthest, pct);
        if (el && pct !== null) {
            if (el === lastEl && lastPct !== null && shown && pct < lastPct - 0.5) problems.push(`back from ${lastPct.toFixed(0)}% to ${pct.toFixed(0)}%`);
            if (el !== lastEl && pct > input.options.minimum * 100 + 2) problems.push(`a bar appeared at ${pct.toFixed(0)}%`);
            lastPct = pct;
        }
        lastEl = el ?? null;
    }, 2);
    for (const [ms, event] of steps) {
        await sleep(ms);
        dom.window.dispatchEvent(new dom.window.Event(event));
    }
    await sleep(1500);
    clearInterval(sample);
    if (lastEl && !document.getElementById('nprogress')) ended(false);
    if (document.getElementById('nprogress')) problems.push('the bar stayed');
    if (problems.length) failures[name] = [...new Set(problems)];
}

process.stdout.write(JSON.stringify(failures));
process.exit(0);
