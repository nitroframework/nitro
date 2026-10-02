/** Fusion::browser()->clipboard(): navigator.clipboard (https pages and localhost). */
function _browser_clipboard(browser) {
    const clipboard = browser.el.ownerDocument.defaultView.navigator.clipboard;
    const available = () => {
        if (clipboard) return true;
        browser.report(new Error('Fusion::browser()->clipboard(): the clipboard is available on https pages and localhost only'));
        return false;
    };

    return {
        write(text, then) {
            if (available()) clipboard.writeText(P.toStr(text)).then(() => then?.()).catch(browser.report);
            return null;
        },
        read(then) {
            if (available()) clipboard.readText().then((text) => then(text)).catch(browser.report);
            return null;
        },
    };
}
