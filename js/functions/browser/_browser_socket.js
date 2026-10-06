/**
 * Fusion::browser()->socket($url): the component's WebSocket to the URL (one per URL), opened the
 * first time. JSON messages arrive decoded; send() encodes what is not a string. What is sent
 * before it opens goes once it does. It reconnects after a drop (1s, 2s, 4s, ... up to 30s) and
 * closes when the component leaves the page.
 */
function _browser_socket(browser, url) {
    const address = P.toStr(url);
    const key = 'socket ' + address;
    if (browser.cache.has(key)) return browser.cache.get(key);

    const win = browser.el.ownerDocument.defaultView;
    const target = new win.URL(address, win.location.href);
    if (target.protocol === 'http:' || target.protocol === 'https:') target.protocol = target.protocol === 'https:' ? 'wss:' : 'ws:';

    const handlers = {};
    const queue = [];
    let socket = null;
    let closed = false;
    let attempts = 0;

    const call = (event, ...args) => {
        try {
            handlers[event]?.(...args);
        } catch (error) {
            browser.report(error);
        }
    };
    const decode = (data) => {
        if (typeof data !== 'string') return data;
        try {
            JSON.parse(data);
        } catch {
            return data;
        }
        return P.jsonDecode(data);
    };
    const connect = () => {
        socket = new win.WebSocket(target.href);
        socket.onopen = () => {
            attempts = 0;
            while (queue.length && socket.readyState === 1) socket.send(queue.shift());
            call('open');
        };
        socket.onmessage = (event) => call('message', decode(event.data));
        socket.onclose = () => {
            call('close');
            if (!closed) win.setTimeout(() => closed || connect(), Math.min(30000, 1000 * 2 ** attempts++));
        };
    };
    const close = () => {
        closed = true;
        browser.cache.delete(key);
        socket?.close();
    };

    const api = {
        onMessage(then) {
            handlers.message = then;
            return api;
        },
        onOpen(then) {
            handlers.open = then;
            return api;
        },
        onClose(then) {
            handlers.close = then;
            return api;
        },
        send(data) {
            const text = typeof data === 'string' ? data : P.jsonEncode(data);
            if (socket?.readyState === 1) socket.send(text);
            else queue.push(text);
            return api;
        },
        close() {
            close();
            return null;
        },
    };

    browser.onDestroy(close);
    browser.cache.set(key, api);
    connect();

    return api;
}
