/**
 * Delegated click actions.
 *
 * The markup carries `data-action="<name>"` (+ data-* arguments) instead of inline
 * inline click-handler attributes, so the Content-Security-Policy needs no 'unsafe-inline' for
 * scripts. One listener on the document finds the nearest [data-action] and calls the
 * matching handler. Handlers look the target module up when clicked, so this file does
 * not depend on script order.
 */
(() => {
    'use strict';

    const num = el => Number(el.dataset.id);

    const handlers = {
        // <div data-action="nav" data-section="hus-section" data-sub="hus-forbrug">
        'nav':                      el => window.SparkNav?.navigateTo(el.dataset.section, el.dataset.sub || undefined),

        // Elbil: manage vehicles / providers
        'elbil-manage-open':        ()  => window.elbilApp?.openManageModal(),
        'vehicle-comparison-sort':  el  => window.sortVehicleComparison?.(el.dataset.sort),
        'provider-create':          ()  => window.elbilApp?.createProvider(),
        'vehicle-edit':             el  => window.elbilApp?.startEditVehicle(num(el)),
        'vehicle-save':             el  => window.elbilApp?.saveVehicle(num(el)),
        'vehicle-cancel':           el  => window.elbilApp?.cancelEditVehicle(num(el), el.dataset.name),
        'provider-edit':            el  => window.elbilApp?.startEditProvider(num(el)),
        'provider-save':            el  => window.elbilApp?.saveProvider(num(el)),
        'provider-cancel':          el  => window.elbilApp?.cancelEditProvider(num(el), el.dataset.name),
        'provider-delete':          el  => window.elbilApp?.deleteProvider(num(el)),
    };

    document.addEventListener('click', (e) => {
        const el = e.target.closest('[data-action]');
        if (!el) return;
        const handler = handlers[el.dataset.action];
        if (handler) handler(el, e);
    });
})();
