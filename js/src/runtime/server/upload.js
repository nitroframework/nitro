/**
 * Files for a property that takes them (Nitro\Upload, or #[Uploads] for a list): uploaded at
 * once, and the property set to what the server answers, Uploads: one, or a list.
 *
 * The files are sent as Viewi's HttpClient sends a file (XMLHttpRequest, which reports upload
 * progress; fetch() does not), as FormData with the component's signed snapshot and the property,
 * so the server only takes files for a component it rendered, on a page the visitor may still
 * see: POST {endpoint}/upload (Http/UploadController). While it uploads, nitro:loading with
 * nitro:target="photo" shows. A file the server refuses (its rules) gives the property's $errors
 * message, as validate() would.
 *
 *   upload()   nitro:model on a file input: its files, and on the input nitro-upload-start,
 *              nitro-upload-progress ({progress: 0-100}), nitro-upload-finish,
 *              nitro-upload-error ({status, message}) and nitro-upload-cancel
 *   send()     the files given, with callbacks: what a JavaScript view's upload() uses
 *              (runtime/view/script.js)
 */
import * as P from '../../php.js';
import { SIGNALS } from '../protocol.js';
import { ErrorBag } from '../component/errors.js';
import { sendFiles } from './request.js';

/** nitro:model on a file input: its chosen files, the input told how it goes. */
export function upload(runtime, component, el, path) {
    const fire = (name, detail = {}) => el.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));

    return send(runtime, component, path, Array.prototype.slice.call(el.files), {
        multiple: el.multiple,
        start: () => fire('nitro-upload-start'),
        progress: (progress) => fire('nitro-upload-progress', { progress }),
        finish: () => fire('nitro-upload-finish'),
        error: (status, message) => fire('nitro-upload-error', { status, message }),
        cancel: () => fire('nitro-upload-cancel'),
    });
}

/**
 * Upload files into a property. Resolves with how it went: { ok: true }, { refused: true } (the
 * server's rules turned them down: the property's $errors say why), { failed: true, status,
 * message } (the request itself failed), { cancelled: true } (a beforeRequest listener stopped it)
 * or { left: true } (the component left the page first).
 *
 * @param {object} on   multiple (a list property), and start(), progress(0-100), finish(), error(status, message), cancel()
 */
export function send(runtime, component, path, files, on = {}) {
    const root = String(path).split('.')[0];

    if (files.length === 0) {
        component.setPath(path, on.multiple ? [] : null);
        return Promise.resolve({ ok: true });
    }

    const form = new FormData();
    form.append('snapshot', JSON.stringify(component.snapshot));
    form.append('property', root);
    for (const file of files) form.append('files[]', file, file.name);

    runtime.loading(component, root, true);
    on.start?.();

    return new Promise((resolve) => {
        const done = (status, text, cancelled = false) => {
            runtime.loading(component, root, false);
            /** It left the page meanwhile: nothing is set or reported (ARCHITECTURE.md, "What Nitro shares"). */
            if (component.destroyed) {
                resolve({ left: true });
                return;
            }
            /** A beforeRequest listener stopped it: nothing sent, nothing set. */
            if (cancelled) {
                on.cancel?.();
                resolve({ cancelled: true });
                return;
            }
            let answer = null;
            try {
                answer = JSON.parse(text);
            } catch {
                /* not JSON: an error page, a proxy */
            }

            if (status === 200 && answer && Array.isArray(answer.uploads)) {
                const uploads = answer.uploads.map(P.decode);
                clearErrors(component, root);
                component.setPath(path, on.multiple ? uploads : uploads[0] ?? null);
                on.finish?.();
                resolve({ ok: true });
                return;
            }

            const message = answer?.message ?? (status === 0 ? 'The server could not be reached.' : `The server answered ${status}.`);
            if (status === 422) setErrors(component, root, answer?.errors?.[root] ?? [message]);
            else runtime.report(component, new Error(`Nitro: uploading ${root}: ${message}`));
            on.error?.(status, message);
            resolve(status === 422 ? { refused: true } : { failed: true, status, message });
        };

        sendFiles(runtime, component, form, (percent) => on.progress?.(percent), done);
    });
}

/** The server refused the file: its messages, as $errors has them after validate(). */
function setErrors(component, field, messages) {
    const all = new Map(component.errors.messages);
    all.set(field, messages.map(P.toStr));
    component.errors = new ErrorBag(all);
    component.dirty.add(SIGNALS.errors);
    component.schedule();
}

function clearErrors(component, field) {
    if (!component.errors.messages.has(field)) return;
    const all = new Map(component.errors.messages);
    all.delete(field);
    component.errors = new ErrorBag(all);
    component.dirty.add(SIGNALS.errors);
    component.schedule();
}
