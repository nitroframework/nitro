/**
 * Fusion::browser()->storage() (localStorage) and ->session() (sessionStorage): values as JSON;
 * plain text another script stored reads as a string. Blocked storage reads as empty.
 */
function _browser_storage(browser, session) {
    const area = () => {
        try {
            return browser.el.ownerDocument.defaultView[session ? 'sessionStorage' : 'localStorage'] ?? null;
        } catch {
            return null;
        }
    };
    const raw = (key) => {
        try {
            return area()?.getItem(P.toStr(key)) ?? null;
        } catch {
            return null;
        }
    };

    return {
        get(key, fallback) {
            const value = raw(key);
            if (value === null) return fallback;
            try {
                JSON.parse(value);
            } catch {
                return value;
            }
            return P.jsonDecode(value);
        },
        set(key, value) {
            try {
                area()?.setItem(P.toStr(key), P.jsonEncode(value));
            } catch {
                // full, or blocked: as if it were not there
            }
            return null;
        },
        has(key) {
            return raw(key) !== null;
        },
        forget(key) {
            try {
                area()?.removeItem(P.toStr(key));
            } catch {
                // blocked
            }
            return null;
        },
    };
}
