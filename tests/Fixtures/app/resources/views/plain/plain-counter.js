/**
 * PlainCounter's view, in plain JavaScript (the "plain" engine the layout registers, which
 * inlines this file): it renders from the handle and again when the properties change.
 */
window.plainViews = window.plainViews ?? {};
window.plainUpdates = window.plainUpdates ?? [];
window.plainUnmounts = window.plainUnmounts ?? 0;

window.plainViews['plain-counter'] = {
    mount(el, fusion) {
        el.innerHTML = '<span class="label"></span>: <span class="count"></span> (<span class="doubled"></span>)'
            + ' <button type="button" class="inc">+2</button> <button type="button" class="save">Save</button>'
            + ' <span class="saved"></span> <span class="names"></span>';
        const $ = (selector) => el.querySelector(selector);
        const render = () => {
            $('.label').textContent = fusion.get('label');
            $('.count').textContent = fusion.get('count');
            $('.doubled').textContent = fusion.get('doubled');
        };
        $('.inc').addEventListener('click', () => fusion.call('increment', 2));
        $('.save').addEventListener('click', () => fusion.call('save').then((said) => ($('.saved').textContent = said)));
        $('.names').textContent = [fusion.properties.join(','), fusion.methods.join(','), fusion.server.join(','), fusion.computed.join(',')].join(' | ');
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
