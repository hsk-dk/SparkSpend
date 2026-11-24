/**
 * Accessibility helpers.
 *
 *  - Every Chart.js chart gets a text alternative (role="img" + aria-label) through one global plugin, so a chart made
 *    tomorrow has it too: its title (the heading of its card), its kind, the points it spans and, for every series, the
 *    lowest, highest and latest value. A doughnut says each slice's share.
 *  - The state the eye reads from a class is also said in ARIA: the page and sub-page chosen in the menus
 *    (aria-current) and the buttons that switch a view or a series on and off (aria-pressed). The code that sets the
 *    classes does not change: a MutationObserver reads them.
 *
 * The text builders are plain functions (tested without a browser: dev/test_a11y_js.js).
 */

const _A11Y_KINDS = { bar: 'Søjlediagram', line: 'Kurvediagram', doughnut: 'Cirkeldiagram', pie: 'Cirkeldiagram', scatter: 'Punktdiagram', radar: 'Radardiagram' };

/** A number as Danish text with at most one decimal; "–" for a missing one. */
function _a11yNum(v) {
    return Number.isFinite(v) ? new Intl.NumberFormat('da-DK', { maximumFractionDigits: 1 }).format(v) : '–';
}

/**
 * The text alternative of a chart.
 * @param {string} title     the chart's name (the heading of its card)
 * @param {string} type      Chart.js type: bar, line, doughnut, pie ...
 * @param {Array}  labels    the x labels (or the slices)
 * @param {Array}  datasets  [{label, data, borderDash}]
 */
/** Whole percentages that add up to 100 (largest remainder); appUtils.percents is the same, this file must stand alone. */
function _a11yPercents(values) {
    const pos = values.map(v => Math.max(0, v));
    const total = pos.reduce((s, v) => s + v, 0);
    if (!(total > 0)) return pos.map(() => 0);
    const raw = pos.map(v => v / total * 100);
    const out = raw.map(Math.floor);
    const left = 100 - out.reduce((s, v) => s + v, 0);
    raw.map((r, i) => [r - out[i], i]).sort((a, b) => b[0] - a[0]).slice(0, left).forEach(([, i]) => { out[i]++; });
    return out;
}

function _a11yChartText(title, type, labels, datasets) {
    const kind = _A11Y_KINDS[type] || 'Diagram';
    const head = (/[.?!]$/.test(title) ? title : title + '.') + ' ' + kind;   // a title that ends in ? does not get a full stop on top
    const series = (datasets || []).filter(ds => Array.isArray(ds.data) && ds.data.length > 0);
    const names = (labels || []).map(String);
    if (series.length === 0) return head + ' uden data.';

    if (type === 'doughnut' || type === 'pie') {
        const values = series[0].data.map(v => Number(v) || 0);
        const total = values.reduce((s, v) => s + v, 0);
        const shares = _a11yPercents(values);   // whole numbers that add up to 100, like the labels on the chart
        const parts = names.slice(0, 8).map((l, i) => `${l} ${total > 0 ? shares[i] + ' %' : _a11yNum(values[i])}`);
        return `${head}: ${parts.join(', ')}.`;
    }

    // Sparklines label their points 0, 1, 2 ...: that is no range worth reading out
    const indexLabels = names.length > 0 && names.every((l, i) => l === String(i));
    const span = (!indexLabels && names.length > 1) ? ` fra ${names[0]} til ${names[names.length - 1]}` : '';
    const points = Math.max(...series.map(ds => ds.data.length));
    const described = series.slice(0, 6).map(ds => {
        // A gap (null) is no value: Number(null) is 0, which would be read out as a real lowest point
        const nums = ds.data.map(v => (v === null || v === undefined ? NaN : typeof v === 'object' ? Number(v.y) : Number(v))).filter(Number.isFinite);
        const name = ds.label || (ds.borderDash ? 'Gennemsnit' : 'Værdier');
        if (nums.length === 0) return `${name}: ingen værdier`;
        const lo = Math.min(...nums), hi = Math.max(...nums), last = nums[nums.length - 1];
        return lo === hi ? `${name}: ${_a11yNum(lo)}` : `${name}: laveste ${_a11yNum(lo)}, højeste ${_a11yNum(hi)}, seneste ${_a11yNum(last)}`;
    });
    const more = series.length > 6 ? ` og ${series.length - 6} serier mere` : '';
    return `${head} med ${points} punkter${span}. ${described.join('. ')}${more}.`;
}

/** The name of a chart: its own data-a11y-title, a card's title, or the nearest heading. */
function _a11yTitleFor(el) {
    const own = el.getAttribute('data-a11y-title');
    if (own) return own;
    const card = el.closest('.dashboard-card');
    if (card) {
        const t = card.querySelector('.dash-title');
        return (t ? t.textContent.trim() : 'Kort') + ', de seneste 30 dage';
    }
    for (let box = el.parentElement; box; box = box.parentElement) {
        const h = box.querySelector(':scope > h3, :scope > h4, :scope > h5, :scope > .card-title, :scope > div > h3, :scope > div > h4, :scope > div > h5');
        if (h && h.textContent.trim()) return h.textContent.trim();
    }
    return 'Diagram';
}

function _a11yLabelChart(chart) {
    const canvas = chart && chart.canvas;
    if (!canvas) return;
    // A bar chart that draws a line (the Elbil sparkline) is a line chart to the ear: the first series says what it is
    const first = (chart.data.datasets || [])[0];
    const type  = (first && first.type) || chart.config.type;
    const text = _a11yChartText(_a11yTitleFor(canvas), type, chart.data.labels, chart.data.datasets);
    canvas.setAttribute('role', 'img');
    canvas.setAttribute('aria-label', text.length > 480 ? text.slice(0, 477) + '…' : text);
}

/** aria-current on the chosen page and sub-page, aria-pressed on the on/off buttons: from the classes the code already sets. */
function _a11ySyncState() {
    document.querySelectorAll('.header-tab-button, .sub-nav-btn').forEach(b => {
        if (b.classList.contains('active')) b.setAttribute('aria-current', 'page'); else b.removeAttribute('aria-current');
    });
    document.querySelectorAll('.hp-mode-btn, .year-chip').forEach(b => {
        b.setAttribute('aria-pressed', b.classList.contains('active') ? 'true' : 'false');
    });
}

(() => {
    if (window.Chart) {
        Chart.register({ id: 'a11yLabel', afterUpdate: _a11yLabelChart });
    }
    document.addEventListener('DOMContentLoaded', () => {
        _a11ySyncState();
        // The skip link moves focus to <main> without touching the address: "#main" would replace the page the router keeps in the
        // hash (#elbil/analyse ...) and a reload would lose it.
        document.querySelector('.skip-link')?.addEventListener('click', e => {
            e.preventDefault();
            document.getElementById('main')?.focus();
        });
        // The menus and toggles are static; the year chips are made later
        const watch = (selector, options) => document.querySelectorAll(selector).forEach(el => new MutationObserver(_a11ySyncState).observe(el, options));
        watch('.header-nav, .sub-nav, .hp-mode-nav', { attributes: true, attributeFilter: ['class'], subtree: true });
        watch('.year-chip-bar', { attributes: true, attributeFilter: ['class'], subtree: true, childList: true });
    });
})();
