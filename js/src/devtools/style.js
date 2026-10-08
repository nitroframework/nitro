/** The overlay's CSS, inside its shadow root: nothing of it reaches the page, nothing of the page reaches it. */
export const STYLE = `
:host { all: initial; }
* { box-sizing: border-box; font: 12px/1.45 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
.badge {
    position: fixed; left: 12px; bottom: 12px; z-index: 2147483646;
    display: flex; gap: .4rem; align-items: center; padding: .35rem .6rem;
    border: 0; border-radius: 999px; background: #18181b; color: #fafafa; cursor: pointer;
    box-shadow: 0 4px 14px rgb(0 0 0 / .25);
}
.badge b { color: #fb7185; font-weight: 700; }
.badge i { font-style: normal; background: #e11d48; color: #fff; border-radius: 999px; padding: 0 .4rem; }
.drawer {
    position: fixed; left: 0; right: 0; bottom: 0; z-index: 2147483647; height: 40vh;
    display: flex; flex-direction: column; background: #18181b; color: #e4e4e7;
    border-top: 2px solid #e11d48; box-shadow: 0 -8px 24px rgb(0 0 0 / .3);
}
.drawer[hidden] { display: none; }
nav { display: flex; gap: .25rem; padding: .35rem .5rem; border-bottom: 1px solid #27272a; }
nav button { border: 0; background: none; color: #a1a1aa; padding: .3rem .6rem; border-radius: 6px; cursor: pointer; }
nav button.current { background: #27272a; color: #fafafa; }
nav button small { color: #fb7185; }
nav [data-close] { margin-left: auto; font-size: 16px; }
.body { overflow: auto; padding: .5rem .75rem; }
table { width: 100%; border-collapse: collapse; }
th { text-align: left; color: #71717a; font-weight: 600; padding: .25rem .5rem; border-bottom: 1px solid #27272a; }
td { padding: .25rem .5rem; border-bottom: 1px solid #1f1f23; vertical-align: top; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
tr[data-component] { cursor: pointer; }
tr[data-component]:hover, tr.current { background: #27272a; }
tr.bad td, p.bad { color: #fb7185; }
pre { margin: .25rem 0; white-space: pre-wrap; word-break: break-word; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: #d4d4d8; }
dl { display: grid; grid-template-columns: max-content 1fr; gap: .25rem 1rem; margin: 0 0 .75rem; }
dt { color: #71717a; }
dd { margin: 0; }
h3 { margin: .5rem 0 .25rem; font-weight: 600; color: #fafafa; }
h3 small { color: #a1a1aa; font-weight: 400; }
.error { border-left: 3px solid #e11d48; padding: .25rem .6rem; margin-bottom: .5rem; background: #1f1f23; }
.error header { color: #a1a1aa; }
.empty, .hint { color: #71717a; }
details summary { cursor: pointer; color: #a1a1aa; }
`;
