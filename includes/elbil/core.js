/**
 * SparkSpend Elbil - core
 *
 * The Elbil tab is split into parts (includes/elbil/*.js) that share one namespace:
 *   Elbil.state    data every part needs (vehicles, providers, the loaded charges, filter elements)
 *   Elbil.modules  each part registers its functions here (charges, optimization, efficiency,
 *                  analytics, yearly, manage, tou); parts call each other as M.<part>.<function>()
 *   Elbil.esc      HTML-escape for text that goes into innerHTML templates
 * The public entry point stays window.elbilApp (main.js), which nav.js and actions.js use.
 */
const Elbil = (() => {
    const state = {
        vehicles: [],
        providers: [],
        allCharges: [],
        filterEl: null, showZeroKwhEl: null, dateRangeEl: null, quickFilterEl: null, chargeTableBodyEl: null,
    };
    const esc = appUtils.escapeHtml;
    return { state, modules: {}, esc };
})();
