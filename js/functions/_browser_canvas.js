/**
 * Fusion::browser()->canvas('chart'): the 2D context of <canvas fusion:ref="chart">. The calls are
 * kept and run in order once the component has rendered, so a canvas the same change adds is
 * there. size() sets the size in CSS pixels and scales for the screen's density.
 */
function _browser_canvas(browser, ref) {
    const name = P.toStr(ref);
    const PROPERTIES = ['fillStyle', 'strokeStyle', 'lineWidth', 'lineCap', 'lineJoin', 'globalAlpha', 'font', 'textAlign', 'textBaseline'];
    const CALLS = ['setLineDash', 'fillRect', 'strokeRect', 'clearRect', 'fillText', 'strokeText', 'beginPath', 'closePath', 'moveTo', 'lineTo', 'rect', 'arc', 'quadraticCurveTo', 'bezierCurveTo', 'fill', 'stroke', 'save', 'restore', 'translate', 'rotate', 'scale'];
    const calls = [];
    const api = {};

    for (const method of ['size', 'clear', ...PROPERTIES, ...CALLS]) {
        api[method] = (...args) => {
            calls.push([method, args]);
            return api;
        };
    }

    browser.after(() => {
        const canvas = browser.ref(name);
        if (typeof canvas?.getContext !== 'function') {
            browser.report(new Error(`Fusion::browser()->canvas('${name}'): the component has no <canvas fusion:ref="${name}">`));
            return;
        }
        const context = canvas.getContext('2d');
        if (!context) return;

        for (const [method, args] of calls) {
            if (method === 'size') {
                const ratio = browser.el.ownerDocument.defaultView.devicePixelRatio || 1;
                const [width, height] = args.map(Number);
                canvas.width = Math.round(width * ratio);
                canvas.height = Math.round(height * ratio);
                canvas.style.width = width + 'px';
                canvas.style.height = height + 'px';
                context.setTransform(ratio, 0, 0, ratio, 0, 0);
            } else if (method === 'clear') {
                const transform = context.getTransform();
                context.setTransform(1, 0, 0, 1, 0, 0);
                context.clearRect(0, 0, canvas.width, canvas.height);
                context.setTransform(transform);
            } else if (PROPERTIES.includes(method)) {
                context[method] = args[0];
            } else {
                context[method](...(method === 'setLineDash' ? [[...P.iter(args[0])].map(([, v]) => v)] : args));
            }
        }
    });

    return api;
}
