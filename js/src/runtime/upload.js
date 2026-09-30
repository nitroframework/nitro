/**
 * fusion:model on a file input: the chosen files are uploaded at once and the property is set to
 * what the server answers, Uploads (Nitro\Fusion\Upload): one, or a list for a multiple input.
 *
 * The files are read as Viewi reads them (DomHelper::getFiles: the input's FileList as a list)
 * and sent as Viewi's HttpClient sends a file (XMLHttpRequest, which reports upload progress;
 * fetch() does not), as FormData with the component's signed snapshot and the property, so the
 * server only takes files for a component it rendered, on a page the visitor may still see:
 * POST {endpoint}/upload (Http/UploadController).
 *
 * On the input: fusion-upload-start, fusion-upload-progress ({progress: 0-100}),
 * fusion-upload-finish, and fusion-upload-error ({status, message}). While it uploads,
 * fusion:loading with fusion:target="photo" shows. A file the server refuses (its rules) gives
 * the property's $errors message, as validate() would.
 */
import * as P from '../php.js';
import { ErrorBag } from './errors.js';

export function upload(runtime, config, component, el, path) {
    const files = Array.prototype.slice.call(el.files);
    const root = String(path).split('.')[0];

    if (files.length === 0) {
        component.setPath(path, el.multiple ? [] : null);
        return Promise.resolve();
    }

    const form = new FormData();
    form.append('snapshot', JSON.stringify(component.snapshot));
    form.append('property', root);
    for (const file of files) form.append('files[]', file, file.name);

    const fire = (name, detail = {}) => el.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
    runtime.loading(component, root, true);
    fire('fusion-upload-start');

    return new Promise((resolve) => {
        const request = new XMLHttpRequest();
        request.open('POST', `${config.endpoint}/upload`, true);
        request.setRequestHeader('Accept', 'application/json');
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        request.setRequestHeader('X-CSRF-TOKEN', config.csrf);
        request.setRequestHeader('X-Fusion', '1');

        request.upload.onprogress = (e) => {
            if (e.lengthComputable) fire('fusion-upload-progress', { progress: Math.round((e.loaded * 100) / e.total) });
        };

        const done = (status, text) => {
            runtime.loading(component, root, false);
            let answer = null;
            try {
                answer = JSON.parse(text);
            } catch {
                /* not JSON: an error page, a proxy */
            }

            if (status === 200 && answer && Array.isArray(answer.uploads)) {
                const uploads = answer.uploads.map(P.decode);
                clearErrors(component, root);
                component.setPath(path, el.multiple ? uploads : uploads[0] ?? null);
                fire('fusion-upload-finish');
            } else {
                const message = answer?.message ?? (status === 0 ? 'The server could not be reached.' : `The server answered ${status}.`);
                if (status === 422) setErrors(component, root, answer?.errors?.[root] ?? [message]);
                else runtime.report(component, new Error(`Fusion: uploading ${root}: ${message}`));
                fire('fusion-upload-error', { status, message });
            }
            resolve();
        };

        request.onload = () => done(request.status, request.responseText);
        request.onerror = () => done(0, '');
        request.send(form);
    });
}

/** The server refused the file: its messages, as $errors has them after validate(). */
function setErrors(component, field, messages) {
    const all = new Map(component.errors.messages);
    all.set(field, messages.map(P.toStr));
    component.errors = new ErrorBag(all);
    component.dirty.add('$errors');
    component.schedule();
}

function clearErrors(component, field) {
    if (!component.errors.messages.has(field)) return;
    const all = new Map(component.errors.messages);
    all.delete(field);
    component.errors = new ErrorBag(all);
    component.dirty.add('$errors');
    component.schedule();
}
