/**
 * PlainCounter's view, in plain JavaScript (the "plain" engine the layout registers, which
 * inlines this file): it renders from the handle and again when the properties change.
 */
window.plainViews = window.plainViews ?? {};
window.plainUpdates = window.plainUpdates ?? [];
window.plainUnmounts = window.plainUnmounts ?? 0;

window.plainViews['plain-counter'] = {
    mount(el, nitro) {
        el.innerHTML = '<span class="label"></span>: <span class="count"></span> (<span class="doubled"></span>)'
            + ' <button type="button" class="inc">+2</button> <button type="button" class="save">Save</button>'
            + ' <span class="saved"></span> <span class="names"></span>';
        const $ = (selector) => el.querySelector(selector);
        const render = () => {
            $('.label').textContent = nitro.get('label');
            $('.count').textContent = nitro.get('count');
            $('.doubled').textContent = nitro.get('doubled');
        };
        $('.inc').addEventListener('click', () => nitro.call('increment', 2));
        $('.save').addEventListener('click', () => nitro.call('save').then((said) => ($('.saved').textContent = said)));
        $('.names').textContent = [nitro.properties.join(','), nitro.methods.join(','), nitro.server.join(','), nitro.computed.join(',')].join(' | ');
        render();

        return {
            update(names) {
                window.plainUpdates.push(names.join(','));
                render();
            },
            unmount() {
                window.plainUnmounts++;
            },
        };
    },
};
