/**
 * Third-party libraries as an app binds them to properties: one Nitro.directive() each, which
 * starts the library on its element, connects its value to the property both ways (set() when
 * the library changes it, watch() when anything else does), and returns what tears it down.
 * Loaded before the runtime (a page's first load), they register on nitro:starting; loaded by a
 * visit into a layout that has them, after Nitro started, they register at once, and Nitro runs
 * them on the elements already in the page.
 *
 * window.libraries counts what each one created and destroyed: the browser tests check that a
 * render, an @if, a new row and a visit never leave one twice, or one behind.
 */
window.libraries = { created: {}, destroyed: {} };
const count = (kind, name) => {
    window.libraries[kind][name] = (window.libraries[kind][name] ?? 0) + 1;
};

const bindings = (Nitro) => {
    /** select2 (jQuery): its own 'change' says the visitor chose; the namespaced one, that we did. */
    Nitro.directive('select2', ({ el, value, component }) => {
        const $el = window.jQuery(el);
        $el.val(component.get(value)).select2();
        $el.on('change.nitro', () => component.set(value, $el.val()));
        const stop = component.watch(value, (now) => $el.val(now).trigger('change.select2'));
        count('created', 'select2');

        return () => {
            stop();
            $el.off('change.nitro').select2('destroy');
            count('destroyed', 'select2');
        };
    });

    /** Flatpickr: onChange says the visitor picked; setDate(..., false) changes it quietly. */
    Nitro.directive('flatpickr', ({ el, value, component }) => {
        const picker = window.flatpickr(el, {
            dateFormat: 'Y-m-d',
            defaultDate: component.get(value),
            onChange: (dates, text) => component.set(value, text),
        });
        const stop = component.watch(value, (now) => picker.setDate(now, false));
        count('created', 'flatpickr');

        return () => {
            stop();
            picker.destroy();
            count('destroyed', 'flatpickr');
        };
    });

    /** Tom Select: its change event; setValue(..., true) is silent. */
    Nitro.directive('tom-select', ({ el, value, component }) => {
        const select = new window.TomSelect(el, {});
        select.setValue(component.get(value), true);
        select.on('change', (now) => component.set(value, now));
        const stop = component.watch(value, (now) => select.setValue(now, true));
        count('created', 'tom-select');

        return () => {
            stop();
            select.destroy();
            count('destroyed', 'tom-select');
        };
    });

    /** Choices: a 'change' event on the element; setChoiceByValue() is quiet. */
    Nitro.directive('choices', ({ el, value, component }) => {
        const choices = new window.Choices(el, { searchEnabled: false, shouldSort: false });
        choices.setChoiceByValue(component.get(value));
        const changed = () => component.set(value, choices.getValue(true));
        el.addEventListener('change', changed);
        const stop = component.watch(value, (now) => choices.setChoiceByValue(now));
        window.choicesOf ??= new WeakMap();
        window.choicesOf.set(el, choices);
        count('created', 'choices');

        return () => {
            stop();
            el.removeEventListener('change', changed);
            choices.destroy();
            count('destroyed', 'choices');
        };
    });

    /**
     * A widget that rebuilds its own markup whenever its value changes, and says so with an event
     * of its own (no 'input' or 'change' for nitro:model to hear): the worst case for a render.
     */
    Nitro.directive('fake-widget', ({ el, value, component }) => {
        const draw = (now) => {
            el.innerHTML = ['one', 'two', 'three'].map((option) => `<button type="button" class="fake-option${option === now ? ' on' : ''}" data-value="${option}">${option}</button>`).join('');
        };
        const picked = (event) => {
            const option = event.target.closest('.fake-option');
            if (!option) return;
            draw(option.dataset.value);
            el.dispatchEvent(new window.CustomEvent('fake:picked', { detail: option.dataset.value }));
        };
        const report = (event) => component.set(value, event.detail);
        draw(component.get(value));
        el.addEventListener('click', picked);
        el.addEventListener('fake:picked', report);
        const stop = component.watch(value, draw);
        count('created', 'fake-widget');

        return () => {
            stop();
            el.removeEventListener('click', picked);
            el.removeEventListener('fake:picked', report);
            el.innerHTML = '';
            count('destroyed', 'fake-widget');
        };
    });

    /** Trix: its <trix-editor> and hidden input made here; trix-change says the visitor typed. */
    Nitro.directive('trix', ({ el, value, component }) => {
        const id = `trix-${Math.random().toString(36).slice(2)}`;
        const input = document.createElement('input');
        input.type = 'hidden';
        input.id = id;
        input.value = component.get(value) ?? '';
        const editor = document.createElement('trix-editor');
        editor.setAttribute('input', id);
        el.append(input, editor);
        let loading = false;
        const changed = () => {
            if (!loading) component.set(value, input.value);
        };
        editor.addEventListener('trix-change', changed);
        const stop = component.watch(value, (now) => {
            loading = true;
            editor.editor?.loadHTML(now ?? '');
            loading = false;
        });
        count('created', 'trix');

        return () => {
            stop();
            editor.removeEventListener('trix-change', changed);
            el.innerHTML = '';
            count('destroyed', 'trix');
        };
    });

    /** Quill: text-change with source 'user' says the visitor typed; 'silent' sets it quietly. */
    Nitro.directive('quill', ({ el, value, component }) => {
        const quill = new window.Quill(el, {});
        const show = (html) => quill.setContents(quill.clipboard.convert({ html: html ?? '' }), 'silent');
        show(component.get(value));
        quill.on('text-change', (delta, old, source) => {
            if (source === 'user') component.set(value, quill.root.innerHTML);
        });
        const stop = component.watch(value, show);
        window.quillOf ??= new WeakMap();
        window.quillOf.set(el, quill);
        count('created', 'quill');

        return () => {
            stop();
            el.innerHTML = '';
            count('destroyed', 'quill');
        };
    });

    /** SortableJS: the list drawn here from the property; onEnd says the visitor moved an item. */
    Nitro.directive('sortable', ({ el, value, component }) => {
        el.innerHTML = (component.get(value) ?? []).map((item) => `<li data-id="${item}">${item}</li>`).join('');
        const sortable = window.Sortable.create(el, { onEnd: () => component.set(value, sortable.toArray()) });
        const stop = component.watch(value, (now) => sortable.sort(now, false));
        window.sortableOf ??= new WeakMap();
        window.sortableOf.set(el, sortable);
        count('created', 'sortable');

        return () => {
            stop();
            sortable.destroy();
            el.innerHTML = '';
            count('destroyed', 'sortable');
        };
    });

    /** noUiSlider: its 'set' event, for the visitor's value; a value it has already is not set again. */
    Nitro.directive('slider', ({ el, value, component }) => {
        window.noUiSlider.create(el, { start: component.get(value), step: 1, range: { min: 0, max: 100 } });
        el.noUiSlider.on('set', (values) => {
            const now = Math.round(Number(values[0]));
            if (now !== component.get(value)) component.set(value, now);
        });
        const stop = component.watch(value, (now) => el.noUiSlider.set(now));
        count('created', 'slider');

        return () => {
            stop();
            el.noUiSlider.destroy();
            count('destroyed', 'slider');
        };
    });

    /** IMask: 'accept' says the value changed; the property holds the digits, the input shows the mask. */
    Nitro.directive('mask', ({ el, value, component }) => {
        const mask = window.IMask(el, { mask: '0000 0000 0000 0000' });
        mask.unmaskedValue = component.get(value) ?? '';
        mask.on('accept', () => {
            if (mask.unmaskedValue !== component.get(value)) component.set(value, mask.unmaskedValue);
        });
        const stop = component.watch(value, (now) => {
            mask.unmaskedValue = now ?? '';
        });
        count('created', 'mask');

        return () => {
            stop();
            mask.destroy();
            count('destroyed', 'mask');
        };
    });
};

if (window.Nitro) bindings(window.Nitro);
else document.addEventListener('nitro:starting', ({ detail: { Nitro } }) => bindings(Nitro));
