/**
 * SparkSpend Dashboard
 *
 * Loads summary data from api.php?action=dashboard and renders the two
 * overview cards (EV + Jordvarme) with mini sparkline bar charts.
 * Clicking a card navigates to the relevant section via SparkNav.
 */

let _evSparkChart  = null;
let _hpSparkChart  = null;
let _husSparkChart = null;
let _hpWeatherAbort = null;
let _dashRefreshInterval = null;
let _dashLoadSeq  = 0;      // guards against overlapping loads rendering out of order
let _dashLastLoad = 0;      // ms timestamp of last successful load
let _forecastLoadedAt = 0;  // elspot forecast is only re-fetched when stale
let _forecastDay      = '';
let _hddCache  = null;      // { key, this, prev, year }: the weather records of the three windows

/** Local calendar date as YYYY-MM-DD. (toISOString() is UTC and is wrong 00-02 Danish time.) */
function _ymd(d = new Date()) {
    const pad = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** Danish number format (decimal comma, thousands dot): _fmt(1234.5, 1) -> "1.234,5" */
function _fmt(n, decimals = 0) {
    return Number(n).toLocaleString('da-DK', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
}

/** Non-destructive status line inside a card (keeps the stat elements and canvas intact). */
const _MONTHS_SHORT = ['jan.', 'feb.', 'mar.', 'apr.', 'maj', 'jun.', 'jul.', 'aug.', 'sep.', 'okt.', 'nov.', 'dec.'];
const _MONTHS_LONG  = ['januar', 'februar', 'marts', 'april', 'maj', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'december'];

/**
 * The stretches the cards compare with, named by their dates: the same days of last month and of the same month last year
 * ("1.–20. okt.", "1.–20. nov. 2025"), cut at the other month's last day, exactly as the server cuts them
 * (DashboardPeriods). `prevMonth` is the whole of last month, which the "Forventet" lines set the forecast against.
 */
function _comparisonLabels(now) {
    const d = now.getDate();
    const range = (year, month) => {
        const end = Math.min(d, new Date(year, month + 1, 0).getDate());
        return (end === 1 ? '1.' : `1.–${end}.`) + ' ' + _MONTHS_SHORT[month];
    };
    const prev = new Date(now.getFullYear(), now.getMonth() - 1, 1);
    return {
        prev: range(prev.getFullYear(), prev.getMonth()),
        year: range(now.getFullYear() - 1, now.getMonth()) + ' ' + (now.getFullYear() - 1),
        prevMonth: _MONTHS_LONG[prev.getMonth()],
    };
}

/**
 * One compact comparison block (a small grid: label, then one row per comparison with the figure right-aligned):
 *     kWh   vs. 1.–20. okt.        ↑99 %
 *           vs. 1.–20. nov. 2025   ↑14 %
 * The labels are the dates compared,
 * not "forrige mdr", so it is clear that a month so far is set against the same days (and the forecast against the whole month).
 * tone 'cost': a rise is red, a fall green. tone 'neutral' (consumption): the arrow only shows the
 * direction - less use is not automatically good (fewer drives), more is not automatically bad. Red is for money only.
 */
function _trendLine(label, pctMonth, pctYear, tone, extra = '', now = new Date(), title = '') {
    const labels = _comparisonLabels(now);
    const part = (txt, pct) => {
        if (pct === null || pct === undefined) return '';
        const flat = Math.round(pct) === 0;          // "↑0 %" would claim a rise of nothing
        const up = pct >= 0;
        const cls = tone === 'cost' && !flat ? (up ? 'trend-up' : 'trend-down') : 'trend-neutral-dir';
        // The dates and the figures are separate cells, so the figures line up under each other
        return `<span class="trend-vs">${txt}</span><span class="${cls} trend-pct">${flat ? '→' : up ? '↑' : '↓'}${_fmt(Math.abs(pct), 0)} %</span>`;
    };
    const parts = [part('vs. ' + labels.prev, pctMonth), part('vs. ' + labels.year, pctYear)].filter(Boolean);
    if (!parts.length) return '';
    return `<div class="trend-line"${title ? ` title="${title}"` : ''}><span class="trend-label">${label}</span>${parts.join('')}${extra}</div>`;
}

function _setCardNote(cardId, text) {
    const body = document.getElementById(cardId)?.querySelector('.dash-body');
    if (!body) return;
    let el = body.querySelector('.dash-note');
    if (!el) {
        el = document.createElement('p');
        el.className = 'dash-note text-muted text-center small py-2 mb-0';
        el.setAttribute('role', 'status');
        body.appendChild(el);
    }
    el.textContent = text;
}

function _clearCardNote(cardId) {
    document.getElementById(cardId)?.querySelector('.dash-note')?.remove();
}

async function loadDashboard(isRefresh = false) {
    // Event listeners pass an Event object here; only a literal true means "background refresh".
    isRefresh = isRefresh === true;
    const seq   = ++_dashLoadSeq;
    const cards = ['ev-dashboard-card', 'hp-dashboard-card', 'hus-dashboard-card'];
    // On the initial load the cards show skeletons; on a background auto-refresh
    // they stay populated, so show a small "Opdaterer…" indicator instead.
    if (isRefresh) {
        const ind = document.getElementById('dashRefreshIndicator');
        if (ind) ind.style.display = '';
    } else {
        cards.forEach(id => document.getElementById(id)?.classList.add('dash-loading'));
    }
    try {
        const data = await Api.get('dashboard');
        if (seq !== _dashLoadSeq) return;   // a newer load has started — drop this response

        // Each card renders independently: one bad card must not blank the others.
        const renderers = [
            ['ev-dashboard-card',  () => renderEvCard(data.ev)],
            ['hp-dashboard-card',  () => renderHeatpumpCard(data.heatpump)],
            ['hus-dashboard-card', () => data.rest
                ? renderHusCard(data.rest, data.hus || null)
                : _setCardNote('hus-dashboard-card', 'Hus-data ikke synkroniseret endnu')],
        ];
        for (const [id, render] of renderers) {
            try {
                _clearCardNote(id);
                render();
            } catch (err) {
                console.error('Dashboard render error (' + id + '):', err);
                _setCardNote(id, 'Data kunne ikke vises');
            }
        }
        _dashLastLoad = Date.now();

        // Anomaly detection runs after card data is available (async, best-effort)
        loadAnomalies();
        // Async cost enrichment for VP and Hus cards
        _enrichCardsWithCost(data.heatpump, data.rest || null, data.ev || null, data.cost || null);
        // Elspot day-ahead forecast widget (self-throttled)
        loadElspotForecast();
        // Sync-status enrichment — async, non-blocking
        _enrichSyncLabels();
    } catch (e) {
        console.error('Dashboard load error:', e);
        if (seq !== _dashLoadSeq) return;
        // Never replace .dash-body: that destroys the stat elements and the card
        // could not recover on the next refresh. Show a note and keep old numbers.
        const msg = isRefresh ? 'Kunne ikke opdatere \u2013 viser seneste data' : 'Data ikke tilg\u00e6ngeligt';
        cards.forEach(id => _setCardNote(id, msg));
    } finally {
        if (seq === _dashLoadSeq) {
            cards.forEach(id => document.getElementById(id)?.classList.remove('dash-loading'));
            const ind = document.getElementById('dashRefreshIndicator');
            if (ind) ind.style.display = 'none';
        }
    }
}

/**
 * The EV card's home/away split in whole percents, of kWh and of kr. Away charging is a small share of the kWh but a larger
 * share of the kr (about 8 % and 16 % in November), which is what lifts the average price per kWh.
 * @returns {{extKwhPct:number, extKrPct:number}|null} null without any kWh or kr to divide
 */
function _evSplitShares(ev) {
    const kwh = _sharePercents([ev.home_kwh, ev.ext_kwh]);
    const kr  = _sharePercents([ev.home_cost, ev.ext_cost]);
    if (kwh[0] + kwh[1] === 0) return null;
    return { extKwhPct: kwh[1], extKrPct: kr[1], hasKr: kr[0] + kr[1] > 0 };
}

/** "Kørsel, 30 dage: 0,44 kr/km · 3,9 km/kWh" for the EV card; null without a price per km. */
function _evDriveLine(ev) {
    if (ev.drive_kr_per_km_30d === null || ev.drive_kr_per_km_30d === undefined) return null;
    const sure = ev.drive_confidence === 'høj';
    const kmPerKwh = ev.drive_km_per_kwh_30d ? ` \u00b7 ${_fmt(ev.drive_km_per_kwh_30d, 1)} km/kWh` : '';
    return {
        text: `Kørsel, 30 dage: ${_fmt(ev.drive_kr_per_km_30d, 2)} kr/km${kmPerKwh}${sure ? '' : ' (usikkert)'}`,
        title: `${_fmt(ev.drive_km_30d, 0)} km de seneste 30 dage, hjemme- og ude-ladning med. ` +
               `Samme beregning som Effektivitet i Elbil. ${ev.drive_why || ''}`.trim(),
    };
}

/** "7-dages snit: 9,8 kWh/dag": where the EV's use is now (short, it shares the row with the trend word). '' without a series. */
function _evSparkCaption(series) {
    if (!series || series.length === 0) return '';
    return `7-dages snit: ${_fmt(series[series.length - 1], 1)} kWh/dag`;
}

/** The tooltip of that caption: where it was at the start of the 30 days. */
function _evSparkTitle(series) {
    if (!series || series.length === 0) return '';
    return `For ${series.length} dage siden: ${_fmt(series[0], 1)} kWh/dag (gennemsnit over de 7 dage til og med den dag)`;
}

function renderEvCard(ev) {
    const card = document.getElementById('ev-dashboard-card');
    if (!card) return;

    const now   = new Date();
    const month = _monthName();
    _setText(card, '.dash-period', month);
    _setText(card, '.dash-stat-charges', String(ev.month_charges));
    _setText(card, '.dash-stat-kwh', _fmt(ev.month_kwh, 1));
    // Charges without a price count as 0 kr — flag it so the total isn't read as complete.
    const unpriced = ev.month_unpriced || 0;
    _setText(card, '.dash-stat-cost', _fmt(ev.month_cost, 0) + ' kr' + (unpriced ? '*' : ''));
    const costEl = card.querySelector('.dash-stat-cost');
    if (costEl) costEl.title = unpriced
        ? `${unpriced} ladning${unpriced === 1 ? '' : 'er'} mangler pris \u2013 prisen er undervurderet`
        : '';
    _setText(card, '.dash-stat-cpkwh', ev.cost_per_kwh !== null ? _fmt(ev.cost_per_kwh, 2) : '—');
    _setText(card, '.dash-projected', ev.projected_cost !== null ? `Forventet: ${_fmt(ev.projected_cost)} kr` : '');
    _setProjectionBasis(card, ev.projection_basis);

    // Home / external split bars: one by kWh, one by kr, so the difference in share is what you see
    const splitWrap = card.querySelector('.dash-split-wrap');
    const shares = _evSplitShares(ev);
    if (splitWrap && shares) {
        const setBar = (basis, home, ext, unit) => {
            const row = splitWrap.querySelector(`.dash-split-row[data-basis="${basis}"]`);
            if (!row) return;
            const total = home + ext;
            row.style.display = total > 0 ? '' : 'none';
            if (!(total > 0)) return;
            row.querySelector('.dash-split-home-fill').style.width = (home / total * 100).toFixed(1) + '%';
            row.querySelector('.dash-split-ext-fill').style.width  = (ext  / total * 100).toFixed(1) + '%';
            row.title = `Hjemme ${_fmt(home, 0)} ${unit} \u00b7 Ude ${_fmt(ext, 0)} ${unit}`;
        };
        setBar('kwh', ev.home_kwh, ev.ext_kwh, 'kWh');
        setBar('kr', ev.home_cost, ev.ext_cost, 'kr');
        const homeLbl = splitWrap.querySelector('.dash-split-home-lbl');
        const extLbl  = splitWrap.querySelector('.dash-split-ext-lbl');
        if (homeLbl) homeLbl.textContent = 'Hjemme' + (ev.home_cpkwh !== null ? ' · ' + _fmt(ev.home_cpkwh, 2) + '\u00a0kr/kWh' : '');
        if (extLbl)  extLbl.textContent  = ev.ext_kwh > 0 ? 'Ude' + (ev.ext_cpkwh !== null ? ' · ' + _fmt(ev.ext_cpkwh, 2) + '\u00a0kr/kWh' : '') : '';
        _setText(splitWrap, '.dash-split-share', ev.ext_kwh > 0 && shares.hasKr
            ? `Ude: ${shares.extKwhPct}\u00a0% af kWh \u00b7 ${shares.extKrPct}\u00a0% af kr` : '');
        splitWrap.style.display = '';
    }
    const driveEl = card.querySelector('.dash-drive');
    if (driveEl) {
        const drive = _evDriveLine(ev);
        driveEl.style.display = drive ? '' : 'none';
        if (drive) { driveEl.textContent = drive.text; driveEl.title = drive.title; }
    }

    const showTrends = now.getDate() >= 5;
    const trendEl = card.querySelector('.dash-trend');
    if (trendEl) {
        let html = '';
        if (showTrends) {
            html += _trendLine('kWh', ev.pct_change_kwh, ev.pct_change_year_kwh, 'neutral');
            html += _trendLine('Pris', ev.pct_change_cost, ev.pct_change_year_cost, 'cost');
        }
        // Driver note: flag if external charging share shifted significantly vs. prev period
        if (showTrends && ev.prev_ext_kwh_pct !== null && ev.month_kwh > 0) {
            const curExtPct  = Math.round(ev.ext_kwh / ev.month_kwh * 100);
            const ppChange   = Math.round(curExtPct - ev.prev_ext_kwh_pct);
            if (Math.abs(ppChange) >= 5) {
                const up = ppChange > 0;
                html += `<div class="trend-driver">` +
                        `Udeladning: ${curExtPct}% af kWh${shares && shares.hasKr ? `, ${shares.extKrPct}% af kr` : ''} (${up ? '↑' : '↓'}${Math.abs(ppChange)}\u00a0pp) ` +
                        `— ${up ? 'trækker gennemsnitsprisen op' : 'trækker gennemsnitsprisen ned'}</div>`;
            }
        }
        trendEl.innerHTML = html;
    }

    const evSparkStart = new Date(); evSparkStart.setDate(evSparkStart.getDate() - 29); evSparkStart.setHours(0,0,0,0);
    // Charging comes in lumps, so the card draws the 7-day mean (kWh per day) rather than the single days
    const evSeries = Array.isArray(ev.sparkline_7d) && ev.sparkline_7d.length ? ev.sparkline_7d : null;
    _evSparkChart = _renderSparkline('ev-sparkline', evSeries || ev.sparkline, '#224b81', _evSparkChart, evSparkStart,
        evSeries ? { kind: 'line', unit: 'kWh/dag (7-dages snit)', seriesLabel: '7-dages gennemsnit, kWh pr. dag' } : {});
    _renderSparklineTrend('ev-dashboard-card', evSeries || ev.sparkline,
        evSeries ? _evSparkCaption(evSeries) : _sparkCaption(ev.sparkline, 'kWh/dag'), evSeries ? _evSparkTitle(evSeries) : '');
}

function renderHeatpumpCard(hp) {
    const card = document.getElementById('hp-dashboard-card');
    if (!card) return;

    const now   = new Date();
    const month = _monthName();
    _setText(card, '.dash-period', month);
    _setText(card, '.dash-stat-kwh', _fmt(hp.month_kwh, 1));
    _setText(card, '.dash-stat-daily', hp.daily_avg_kwh !== null ? _fmt(hp.daily_avg_kwh, 1) : '—');
    _setText(card, '.dash-projected', hp.projected_kwh !== null
        ? `Forventet: ${_fmt(hp.projected_kwh)} kWh (hele ${_comparisonLabels(now).prevMonth}: ${_fmt(hp.prev_month_full_kwh)})`
        : '');
    _setProjectionBasis(card, hp.projection_basis);

    const showTrends = now.getDate() >= 5;
    const trendEl = card.querySelector('.dash-trend');
    if (trendEl) {
        let html = '';
        if (showTrends) html += _trendLine('kWh', hp.pct_change, hp.pct_change_year, 'neutral');
        trendEl.innerHTML = html;
        trendEl.className = 'dash-trend';
    }

    const hpSparkStart = new Date(); hpSparkStart.setDate(hpSparkStart.getDate() - 29); hpSparkStart.setHours(0,0,0,0);
    _hpSparkChart = _renderSparkline('hp-sparkline', hp.sparkline, '#0ea495', _hpSparkChart, hpSparkStart);
    _renderSparklineTrend('hp-dashboard-card', hp.sparkline, _sparkCaption(hp.sparkline, 'kWh/dag'));
    _renderSeasonBadge(card, hp.pct_change_year);

    // Abort any in-flight weather enrichment from a previous render
    if (_hpWeatherAbort) { _hpWeatherAbort.abort(); _hpWeatherAbort = null; }
    // Enrich with weather-normalised year-over-year trend (async, non-blocking)
    _enrichHpWithWeather(card, hp);
}

/**
 * What the house meter was spent on so far: home EV charging, heat pump and the rest, in the colours of their cards.
 * The Hus card itself shows only the rest (an amber third of the meter); this bar puts that number in its place.
 * @param {object|null} hus  the payload's `hus` block (house meter total, ev_home_kwh, hp_kwh)
 * @param {object|null} rest the payload's `rest` block
 * @returns {{caption:string, label:string, track:string, legend:string}|null} null without a house meter or any kWh
 */
function _husComposition(hus, rest) {
    if (!hus || !rest) return null;
    const vals = [hus.ev_home_kwh, hus.hp_kwh, rest.month_kwh].map(v => (Number.isFinite(Number(v)) ? Math.max(0, Number(v)) : 0));
    const total = vals.reduce((s, v) => s + v, 0);
    if (!(total > 0)) return null;
    const pct = _sharePercents(vals);
    const parts = _SHARE_PARTS.map((x, i) => ({ ...x, value: vals[i], pct: pct[i] }));
    const caption = `Husmåler hidtil: ${_fmt(hus.month_kwh, 0)} kWh`;
    return {
        caption,
        label: `${caption}: ${parts.map(x => `${x.label} ${x.pct} %`).join(', ')}`,
        track: parts.map(x =>
            `<span class="dash-comp-seg" style="width:${(x.value / total * 100).toFixed(2)}%;background:${x.color}" ` +
            `title="${x.label}: ${_fmt(x.value, 0)} kWh (${x.pct} %)"></span>`).join(''),
        legend: parts.map(x =>
            `<span class="dash-comp-key${x.key === 'rest' ? ' dash-comp-this' : ''}"><i class="dash-comp-dot" style="background:${x.color}"></i>` +
            `${x.label} <b>${x.pct}&nbsp;%</b></span>`).join(''),
    };
}

// The Hus card shows ONLY "Rest": the house meter minus the heat pump and EV home
// charging (computed by the server). EV and heat pump have their own cards.
function renderHusCard(rest, hus = null) {
    const card = document.getElementById('hus-dashboard-card');
    if (!card) return;

    const now   = new Date();
    const month = _monthName();
    _setText(card, '.dash-period', `Øvrigt forbrug · ${month}`);
    _setText(card, '.dash-stat-kwh', _fmt(rest.month_kwh, 1));
    _setText(card, '.dash-stat-daily', rest.daily_avg_kwh !== null ? _fmt(rest.daily_avg_kwh, 1) : '—');
    const prevFull = rest.prev_month_full_kwh > 0 ? Math.round(rest.prev_month_full_kwh) : null;
    _setText(card, '.dash-projected', rest.projected_kwh !== null
        ? `Forventet: ${_fmt(rest.projected_kwh)} kWh` + (prevFull ? ` (hele ${_comparisonLabels(now).prevMonth}: ${_fmt(prevFull)})` : '')
        : '');
    _setProjectionBasis(card, rest.projection_basis);

    const comp = _husComposition(hus, rest);
    const compEl = card.querySelector('.dash-comp');
    if (compEl) {
        compEl.style.display = comp ? '' : 'none';
        if (comp) {
            compEl.querySelector('.dash-comp-cap').textContent = comp.caption;
            const track = compEl.querySelector('.dash-comp-track');
            track.innerHTML = comp.track;
            track.setAttribute('aria-label', comp.label);
            compEl.querySelector('.dash-comp-legend').innerHTML = comp.legend;
        }
    }

    const showTrends = now.getDate() >= 5;
    const trendEl = card.querySelector('.dash-trend');
    if (trendEl) {
        let html = '';
        if (showTrends) html += _trendLine('kWh', rest.pct_change, rest.pct_change_year, 'neutral');
        if (rest.negative_days > 0) {
            html += `<div class="trend-neutral" title="Husmåler minus jordvarme minus elbil er negativ på disse dage og vises som 0. Tjek om målerne er i synk.">` +
                    `⚠️ Målerne stemmer ikke på ${rest.negative_days} dag${rest.negative_days === 1 ? '' : 'e'} ` +
                    `(${_fmt(rest.negative_kwh, 1)} kWh)</div>`;
        }
        trendEl.innerHTML = html;
        trendEl.className = 'dash-trend';
    }

    const husSparkStart = new Date(); husSparkStart.setDate(husSparkStart.getDate() - 29); husSparkStart.setHours(0,0,0,0);
    _husSparkChart = _renderSparkline('hus-sparkline', rest.sparkline, '#9e5f00', _husSparkChart, husSparkStart);
    _renderSparklineTrend('hus-dashboard-card', rest.sparkline, _sparkCaption(rest.sparkline, 'kWh/dag'));
    _renderSeasonBadge(card, rest.pct_change_year);
}

/**
 * Why a raw change and the weather-corrected one differ: the stretch it is set against had another number of degree days.
 * `against` names that stretch ("samme periode sidste år", "1.–20. okt."). Returns '' while the weather was about the same
 * (under 10 %), so the line only appears when it explains something.
 */
function _hddExplanation(hddThis, hddLast, against = 'samme periode sidste år') {
    const diff = Math.round((hddThis / hddLast - 1) * 100);
    if (!Number.isFinite(diff) || Math.abs(diff) < 10) return '';
    const colder = diff > 0;
    return `${colder ? 'Koldere' : 'Varmere'} vejr end ${against}: ` +
        `${_fmt(Math.abs(diff), 0)} % ${colder ? 'flere' : 'færre'} gradedage`;
}

/** The share of today that has passed, limited to 5-100 % exactly as the server does (DashboardPeriods::dayFraction). */
function _dayFraction(now) {
    const start = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const next  = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1);
    return Math.max(0.05, Math.min(1, (now - start) / (next - start)));
}

/** The stretches the weather correction compares with: the same days of last month and of last year, cut like the server cuts them. */
function _comparisonWindows(now) {
    const d = now.getDate();
    const win = (year, month) => {
        const mm  = String(month + 1).padStart(2, '0');
        const end = Math.min(d, new Date(year, month + 1, 0).getDate());
        return { start: `${year}-${mm}-01`, end: `${year}-${mm}-${String(end).padStart(2, '0')}` };
    };
    const prev = new Date(now.getFullYear(), now.getMonth() - 1, 1);
    return { prev: win(prev.getFullYear(), prev.getMonth()), year: win(now.getFullYear() - 1, now.getMonth()) };
}

/**
 * The degree days of a window. Its last day counts pro rata (`lastWeight`) when it is the date that is only part over (today,
 * or the same date in another month): the kWh it is set against count that day pro rata too (DashboardPeriods), and a day's
 * degree days are a whole day's worth even if the day is not over.
 */
function _hddSum(records, lastDay, lastWeight) {
    return (records || []).reduce((s, r) => s + r.hdd * (r.date === lastDay ? lastWeight : 1), 0);
}

/** kWh per degree day now against then: the change in percent, or null without degree days or kWh to divide. */
function _weatherCorrectedPct(kwhNow, hddNow, kwhThen, hddThen) {
    if (!(hddNow > 0) || !(hddThen > 0) || !(kwhNow >= 0) || !(kwhThen > 0)) return null;
    return ((kwhNow / hddNow) / (kwhThen / hddThen) - 1) * 100;
}

async function _enrichHpWithWeather(card, hp) {
    const lat = window.sparkConfig?.weatherLat || '';
    const lon = window.sparkConfig?.weatherLon || '';
    const lastYearKwh = hp.last_year_period_kwh ?? null;
    const prevKwh     = hp.prev_month_kwh ?? null;
    if (!lat || !lon || hp.month_kwh === null || !(lastYearKwh > 0 || prevKwh > 0)) return;

    const controller = new AbortController();
    _hpWeatherAbort  = controller;

    const now   = new Date();
    const mm    = String(now.getMonth() + 1).padStart(2, '0');
    const today = _ymd(now);
    const w     = _comparisonWindows(now);

    try {
        // Degree days only change once a day — reuse the records across 5-minute refreshes (the weights depend on the time, so
        // the records are kept, not the sums).
        const key = `${today}|${lat}|${lon}`;
        let rec;
        if (_hddCache && _hddCache.key === key) {
            rec = _hddCache;
        } else {
            const get = (start, end) => Api.get('weather', { start, end, lat, lon }, { signal: controller.signal });
            const [resThis, resPrev, resYear] = await Promise.all([
                get(`${now.getFullYear()}-${mm}-01`, today), get(w.prev.start, w.prev.end), get(w.year.start, w.year.end),
            ]);
            rec = { key, this: resThis.records || [], prev: resPrev.records || [], year: resYear.records || [] };
            if (rec.this.length && rec.prev.length && rec.year.length) _hddCache = rec;
        }

        const frac    = _dayFraction(now);
        const d       = now.getDate();
        const dayOf   = iso => Number(iso.slice(8, 10));
        const hddThis = _hddSum(rec.this, today, frac);
        const hddPrev = _hddSum(rec.prev, w.prev.end, dayOf(w.prev.end) === d ? frac : 1);
        const hddYear = _hddSum(rec.year, w.year.end, dayOf(w.year.end) === d ? frac : 1);

        const pctPrev = _weatherCorrectedPct(hp.month_kwh, hddThis, prevKwh, hddPrev);
        const pctYear = _weatherCorrectedPct(hp.month_kwh, hddThis, lastYearKwh, hddYear);
        if (pctPrev === null && pctYear === null) return;

        const trendEl = card.querySelector('.dash-trend');
        if (!trendEl) return;
        const labels = _comparisonLabels(now);
        trendEl.innerHTML += _trendLine('Vejrkorr.', pctPrev, pctYear, 'neutral', '', now,
            'Justeret for gradedage (HDD 17°C): kWh pr. gradedag, mod det samme for de to sammenligningsperioder');
        for (const [pct, hddThen, against] of [[pctPrev, hddPrev, labels.prev], [pctYear, hddYear, labels.year]]) {
            if (pct === null) continue;   // nothing was compared with that stretch, so there is nothing to explain
            const why = _hddExplanation(hddThis, hddThen, against);
            if (why) {
                trendEl.innerHTML += `<div class="trend-explain text-muted small" title="Gradedage (HDD 17°C), 1. til i dag, mod samme dage i sammenligningsperioden">${why}</div>`;
            }
        }
    } catch (e) {
        if (e.name !== 'AbortError') console.warn('_enrichHpWithWeather:', e);
    }
}

/** Bar fills of a sparkline: the full (opaque) colour for the finished days, a pale one for the last bar, today, which is only part over. */
function _sparkBarColors(count, color) {
    return Array.from({ length: count }, (_, i) => (i === count - 1 ? color + '40' : color));
}

/** Mean of the finished days (all but the last point, today) and today's value so far; avg null without a finished day. */
function _sparkStats(values) {
    if (!values || values.length === 0) return { avg: null, today: null };
    const done = values.slice(0, -1);
    return {
        avg: done.length ? done.reduce((s, v) => s + v, 0) / done.length : null,
        today: values[values.length - 1],
    };
}

/** "Gns. 9,2 kWh/dag · i dag hidtil 5,3": the scale of a sparkline that has no axis. '' when there is nothing to say. */
function _sparkCaption(values, unit) {
    const s = _sparkStats(values);
    if (s.avg === null) return '';
    return `Gns. ${_fmt(s.avg, 1)} ${unit} · i dag hidtil ${_fmt(s.today, 1)}`;
}

/**
 * opts.kind 'line': a filled line instead of bars (the EV card draws a 7-day mean, which is not a per-day figure).
 * Bars get a dashed line at the mean of the finished days. opts.unit names the unit in the tooltip.
 */
function _renderSparkline(canvasId, dataPoints, color, existing, monthStart = null, opts = {}) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return null;
    if (existing) existing.destroy();

    const line = opts.kind === 'line';
    const unit = opts.unit || 'kWh';
    const avg = line ? null : _sparkStats(dataPoints).avg;
    const datasets = [line
        ? { type: 'line', label: opts.seriesLabel || 'Værdi pr. dag', data: dataPoints, borderColor: color, backgroundColor: color + '33', borderWidth: 2, fill: true, tension: 0.3, pointRadius: 0, pointHoverRadius: 3 }
        : { label: opts.seriesLabel || 'kWh pr. dag', data: dataPoints, backgroundColor: _sparkBarColors(dataPoints.length, color), borderColor: color, borderWidth: 1, borderRadius: 2 }];
    if (avg !== null) {
        datasets.push({ type: 'line', label: 'Gennemsnit', data: dataPoints.map(() => avg), borderColor: color, borderWidth: 1, borderDash: [4, 3], pointRadius: 0, pointHoverRadius: 0, fill: false });
    }

    return new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: { labels: dataPoints.map((_, i) => i), datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend:  { display: false },
                tooltip: monthStart ? {
                    enabled: true,
                    filter: (item) => item.datasetIndex === 0,   // not the dashed mean line
                    callbacks: {
                        title: (items) => {
                            const d = new Date(monthStart);
                            d.setDate(d.getDate() + items[0].dataIndex);
                            const day = d.toLocaleDateString('da-DK', { day: 'numeric', month: 'short' });
                            return !line && items[0].dataIndex === dataPoints.length - 1 ? `${day} (i dag, hidtil)` : day;
                        },
                        label: (item) => appUtils.num(item.raw, 1) + ' ' + unit
                    }
                } : { enabled: false },
                datalabels: { display: false }
            },
            scales: {
                x: { display: false },
                y: { display: false, beginAtZero: true }
            },
            animation: { duration: 500 }
        }
    });
}

let _forecastChart = null;

async function loadElspotForecast(force = false) {
    const area = window.sparkConfig?.elspotArea || '';
    const gln  = window.sparkConfig?.elspotGln  || '';
    const card = document.getElementById('elspot-forecast-card');
    if (!area || !gln || !card) return;

    const now      = new Date();
    const today    = _ymd(now);
    // Prices rarely change: skip the fetch on 5-minute refreshes unless the day
    // rolled over or the data is older than 30 minutes (tomorrow's prices land ~13:00).
    if (force !== true && _forecastDay === today && Date.now() - _forecastLoadedAt < 30 * 60 * 1000) return;
    const tomorrow = new Date(now); tomorrow.setDate(now.getDate() + 1);
    const tmrStr   = _ymd(tomorrow);
    const tmrLabel = tomorrow.toLocaleDateString('da-DK', { weekday: 'long', day: 'numeric', month: 'short' });
    const todLabel = now.toLocaleDateString('da-DK', { weekday: 'long', day: 'numeric', month: 'short' });

    try {
        let data;
        try {
            data = await Api.get('elspot-prices', { start: today, end: tmrStr, area, gln, format: 'hourly', tariff: 'low' });
        } catch (e) {
            if (e instanceof Api.ApiError && e.status) { _showForecastProblem(card, 'Elpriserne kunne ikke hentes (' + e.message + ').'); return; }
            throw e;
        }
        const allRows = (data.records || []);

        // Prefer tomorrow's day-ahead prices; if not published yet, fall back to
        // the rest of today so the widget is never blank.
        let hours = allRows.filter(r => r.date === tmrStr).sort((a, b) => a.hour - b.hour);
        const showingTomorrow = hours.length > 0;
        let forecastLabel = tmrLabel;
        let titlePrefix = 'Elpris i morgen';

        if (!showingTomorrow) {
            const curHour = now.getHours();
            // Remaining hours of today (from the current hour onward)
            hours = allRows
                .filter(r => r.date === today && r.hour >= curHour)
                .sort((a, b) => a.hour - b.hour);
            // If nothing left today (late evening), show the full day instead
            if (hours.length === 0) {
                hours = allRows.filter(r => r.date === today).sort((a, b) => a.hour - b.hour);
            }
            forecastLabel = todLabel;
            titlePrefix = 'Elpris i dag';
        }

        // Update the card heading to reflect which day we're showing
        const titleEl = card.querySelector('h3');
        if (titleEl) titleEl.textContent = titlePrefix;

        if (hours.length === 0) {
            // No price data at all — show informational message
            card.style.display = '';
            const chartEl = document.getElementById('elspot-forecast-chart');
            if (chartEl) chartEl.innerHTML = '<p class="text-muted text-center py-3">Ingen elpriser tilgængelige lige nu</p>';
            const windowEl = document.getElementById('elspot-cheapest-window');
            if (windowEl) windowEl.innerHTML = '';
            const meta = document.getElementById('elspot-forecast-meta');
            if (meta) meta.textContent = tmrStr;
            return;
        }

        card.style.display = '';
        const meta = document.getElementById('elspot-forecast-meta');
        if (meta) {
            meta.textContent = forecastLabel + ' · ' + hours.length + ' timer' +
                (showingTomorrow ? '' : ' · morgendagens priser offentliggøres ca. kl. 13:00');
        }

        // Find cheapest consecutive 2-hour window
        let bestIdx = 0, bestSum = Infinity;
        for (let i = 0; i <= hours.length - 2; i++) {
            const s = hours[i].kr_kwh + hours[i + 1].kr_kwh;
            if (s < bestSum) { bestSum = s; bestIdx = i; }
        }
        const cheapH1   = hours[bestIdx].hour;
        const cheapH2   = hours[bestIdx + 1].hour;
        const cheapAvg  = bestSum / 2;
        const cheapEl   = document.getElementById('elspot-cheapest-window');
        if (cheapEl) {
            cheapEl.innerHTML =
                `<span class="badge bg-success me-1"><i class="fas fa-bolt"></i></span>` +
                `Billigste 2-timers ladevindue: <strong>${String(cheapH1).padStart(2,'0')}:00–${String(cheapH2 + 1).padStart(2,'0')}:00</strong>` +
                ` · gns. ${appUtils.num(cheapAvg, 3)} kr/kWh` +
                `<div class="text-muted mt-1">Farven viser timen i forhold til dagens billigste og dyreste, ikke en fast prisgrænse.</div>`;
        }

        // Chart.js bars: one colour per bar by how dear the hour is, the cheapest 2-hour window shaded behind them
        const chartEl = document.getElementById('elspot-forecast-chart');
        if (!chartEl) return;

        const minPrice = Math.min(...hours.map(h => h.kr_kwh));
        const maxPrice = Math.max(...hours.map(h => h.kr_kwh));
        const range    = maxPrice - minPrice || 1;

        const colors = hours.map(h => {
            const t = (h.kr_kwh - minPrice) / range; // 0=cheap, 1=expensive
            if (t < 0.33) return '#22c55e';
            if (t < 0.66) return '#f59e0b';
            return '#ef4444';
        });
        const hh = n => String(n).padStart(2, '0') + ':00';

        if (_forecastChart) { _forecastChart.destroy(); _forecastChart = null; }
        chartEl.innerHTML = '<div style="position:relative;height:180px"><canvas></canvas></div>';
        _forecastChart = new Chart(chartEl.querySelector('canvas').getContext('2d'), {
            type: 'bar',
            data: {
                labels: hours.map(h => hh(h.hour)),
                datasets: [{
                    label: 'kr/kWh',
                    data: hours.map(h => Math.round(h.kr_kwh * 1000) / 1000),
                    backgroundColor: colors,
                    borderWidth: 0,
                    borderRadius: 2,
                    categoryPercentage: 0.9,
                    barPercentage: 0.9,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                plugins: {
                    legend: { display: false },
                    datalabels: { display: false },
                    tooltip: {
                        callbacks: {
                            title: items => `${hh(hours[items[0].dataIndex].hour)}–${hh(hours[items[0].dataIndex].hour + 1)}`,
                            label: item => appUtils.num(item.raw, 3) + ' kr/kWh',
                        },
                    },
                },
                scales: {
                    x: { ticks: { maxRotation: 0, minRotation: 0, autoSkip: true, maxTicksLimit: 12, font: { size: 11 } }, grid: { display: false } },
                    y: { beginAtZero: true, ticks: { callback: v => appUtils.num(v, 2), font: { size: 11 } }, title: { display: true, text: 'kr/kWh', font: { size: 12 } } },
                },
            },
            plugins: [{
                id: 'cheapestWindow',
                beforeDatasetsDraw(chart) {
                    const { ctx, chartArea, scales } = chart;
                    const first = scales.x.getPixelForValue(bestIdx), last = scales.x.getPixelForValue(bestIdx + 1);
                    const half = (last - first) / 2;
                    ctx.save();
                    ctx.fillStyle = 'rgba(34, 197, 94, 0.15)';
                    ctx.fillRect(first - half, chartArea.top, last - first + 2 * half, chartArea.bottom - chartArea.top);
                    ctx.font = '600 12px sans-serif';
                    const w = ctx.measureText('Billigst').width + 12;
                    ctx.fillStyle = '#dcfce7';
                    ctx.fillRect(first - half, chartArea.top, w, 18);
                    ctx.fillStyle = '#166534';
                    ctx.textBaseline = 'middle';
                    ctx.fillText('Billigst', first - half + 6, chartArea.top + 9);
                    ctx.restore();
                },
            }],
        });
        _forecastDay      = today;
        _forecastLoadedAt = Date.now();
    } catch (e) {
        console.warn('loadElspotForecast:', e);
        _showForecastProblem(card, 'Elpriserne kunne ikke vises.');
    }
}

/** Keep the price card visible with the reason instead of silently hiding it. */
function _showForecastProblem(card, text) {
    card.style.display = '';
    const chartEl = document.getElementById('elspot-forecast-chart');
    if (chartEl) chartEl.innerHTML = `<p class="text-muted text-center py-3">${text}</p>`;
    const windowEl = document.getElementById('elspot-cheapest-window');
    if (windowEl) windowEl.innerHTML = '';
}

// The three consumers of the house meter, in the colours of their cards. "Øvrigt" is the Hus card's name for what
// is left of the house meter after the heat pump and home EV charging.
const _SHARE_PARTS = [
    { key: 'ev',   label: 'EV hjemme', color: '#224b81' },
    { key: 'hp',   label: 'Jordvarme', color: '#0ea495' },
    { key: 'rest', label: 'Øvrigt',    color: '#9e5f00' },
];
const _SHARE_SUB_COLOR = '#94a3b8';

/** Whole percents of the (positive) values that add up to exactly 100 (largest remainder); all 0 when there is nothing. */
function _sharePercents(values) {
    const pos = values.map(v => (Number.isFinite(v) && v > 0 ? v : 0));
    const total = pos.reduce((s, v) => s + v, 0);
    if (!(total > 0)) return pos.map(() => 0);
    const raw = pos.map(v => v / total * 100);
    const out = raw.map(Math.floor);
    const left = 100 - out.reduce((s, v) => s + v, 0);
    raw.map((r, i) => [r - out[i], i]).sort((a, b) => b[0] - a[0]).slice(0, left).forEach(([, i]) => { out[i]++; });
    return out;
}

/**
 * Two stacked bars for the band above the cards: where the house meter's kWh went so far, and what the month's
 * kr are expected to be, in the same three colours. The point is the difference between them: the EV is about half of
 * the kWh but, charged at night, a smaller share of the kr. kwh = {ev, hp, rest} (month to date); cost = {ev, hp,
 * rest, sub} (expected for the whole month, the same figures as the total). '' when either is empty.
 */
function _costShareBars(kwh, cost) {
    const kVals = _SHARE_PARTS.map(x => Number(kwh[x.key]) || 0);
    const cVals = _SHARE_PARTS.map(x => Number(cost[x.key]) || 0).concat([Number(cost.sub) || 0]);
    const kTotal = kVals.reduce((s, v) => s + Math.max(0, v), 0);
    const cTotal = cVals.reduce((s, v) => s + Math.max(0, v), 0);
    if (!(kTotal > 0) || !(cTotal > 0)) return '';

    const kPct = _sharePercents(kVals);
    const cPct = _sharePercents(cVals);
    const seg = (label, value, unit, pct, color, total) =>
        `<span class="tc-seg" style="width:${(Math.max(0, value) / total * 100).toFixed(2)}%;background:${color}" ` +
        `title="${label}: ${_fmt(value, 0)} ${unit} (${pct} %)"></span>`;
    const row = (caption, total, unit, parts) =>
        `<div class="tc-row"><span class="tc-label">${caption} <b>${_fmt(total, 0)}</b></span>` +
        `<div class="tc-track" role="img" aria-label="${caption} ${_fmt(total, 0)}: ` +
        `${parts.map(x => `${x.label} ${x.pct} %`).join(', ')}">` +
        parts.map(x => seg(x.label, x.value, unit, x.pct, x.color, total)).join('') + `</div></div>`;

    const kParts = _SHARE_PARTS.map((x, i) => ({ ...x, value: kVals[i], pct: kPct[i] }));
    const cParts = _SHARE_PARTS.map((x, i) => ({ ...x, value: cVals[i], pct: cPct[i] }));
    if (cVals[3] > 0) cParts.push({ label: 'Abonnement', color: _SHARE_SUB_COLOR, value: cVals[3], pct: cPct[3] });

    const legend = _SHARE_PARTS.map((x, i) =>
        `<span class="tc-key"><i class="tc-dot" style="background:${x.color}"></i>${x.label} ` +
        `<b>${kPct[i]}\u00a0%</b> af kWh \u00b7 <b>${cPct[i]}\u00a0%</b> af kr</span>`).join('') +
        (cVals[3] > 0 ? `<span class="tc-key"><i class="tc-dot" style="background:${_SHARE_SUB_COLOR}"></i>Abonnement <b>${cPct[3]}\u00a0%</b> af kr</span>` : '');
    // On a phone the legend is four lines under two bars, about a sixth of the screen before the first card: folded there
    // (the bars' own title and aria-label carry the figures), open on a wide screen where it is one line.
    const wide = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia('(min-width: 601px)').matches : true;
    return `<div class="total-cost-bars">` +
        row('kWh hidtil', kTotal, 'kWh', kParts) +
        row('kr forventet', cTotal, 'kr', cParts) +
        `<details class="tc-fold"${wide ? ' open' : ''}><summary>Fordeling i procent</summary><div class="tc-legend">${legend}</div></details></div>`;
}

// Turning the phone sideways (or widening the window) opens or folds every legend, as it would have been drawn
if (typeof window !== 'undefined' && window.matchMedia) {
    const _tcWide = window.matchMedia('(min-width: 601px)');
    if (_tcWide.addEventListener) _tcWide.addEventListener('change', e => document.querySelectorAll('.tc-fold').forEach(d => { d.open = e.matches; }));
}

// Electricity cost estimates. The elvarme model (rates, weighting, high/low split,
// projection) is computed on the server (includes/CostEstimates.php) and arrives as
// `cost` in the dashboard payload; this only displays it.
function _enrichCardsWithCost(hp, rest, ev, cost) {
    if (!cost) return;

    const basisNote = cost.basis === 'weighted'
        ? 'vægtet efter hvornår strømmen blev brugt (timepriser)'
        : 'gennemsnit af månedens priser';

    // Jordvarme card — VP share of the elvarme blend
    if (hp && hp.month_kwh > 0) {
        const el = document.getElementById('hp-cost-stat');
        if (el) {
            el.querySelector('.dash-est-cost').textContent = _fmt(cost.hp_mtd, 0);
            el.title = `Estimeret elvarme-model, ${basisNote} (inkl. systemtarif, nettarif, elafgift og moms)`;
            el.style.display = '';
        }
    }
    // Hus card — the "Rest" share of the elvarme blend (EV is priced by Monta, VP in its own card)
    if (rest && rest.month_kwh > 0) {
        const el = document.getElementById('hus-cost-stat');
        if (el) {
            el.querySelector('.dash-est-cost').textContent = _fmt(cost.rest_mtd, 0);
            el.title = `Estimeret pris for det øvrige forbrug, ${basisNote}: første ≈${Math.round(cost.high_kwh_month)} kWh/md af VP+Rest til fuld elafgift, resten til elvarmesats`;
            el.style.display = '';
        }
    }

    // Total electricity cost estimate (projected for the full month). EV is Monta's actual
    // price for home charging (all tariffs and taxes included; away charging is not on the bill); VP and Rest are the elvarme model; the fixed
    // subscription (SUBSCRIPTION_PER_DAY) is its own item.
    if (cost.total_proj > 0) {
        const el = document.getElementById('total-cost-estimate');
        if (el) {
            const perDay = cost.per_day || 0;
            const kwhNow = { ev: ev ? ev.home_kwh : 0, hp: hp ? hp.month_kwh : 0, rest: rest ? rest.month_kwh : 0 };
            el.innerHTML = `<div class="total-cost-top"><span class="total-cost-icon">⚡</span>` +
                `<span class="total-cost-amount">Forventet elregning: ~${cost.total_proj} kr</span>` +
                `<span class="total-cost-breakdown">EV hjemme: ~${Math.round(cost.ev_proj)} · Jordvarme: ~${cost.hp_proj} · Øvrigt: ~${cost.rest_proj}` +
                (perDay > 0 ? ` · Abonnement: ~${_fmt(cost.subscription_proj, 0)} kr` : ' kr · ekskl. abonnement') + `</span></div>` +
                _costShareBars(kwhNow, { ev: cost.ev_proj, hp: cost.hp_proj, rest: cost.rest_proj, sub: perDay > 0 ? cost.subscription_proj : 0 });
            el.title = `Estimat for hele måneden. Hidtil: ~${_fmt(cost.total_mtd, 0)} kr. ` +
                       `EV hjemme: faktisk pris fra Monta (inkl. tariffer og afgifter); resten af måneden til ladeprisen de seneste 8 dage. Ladning ude er ikke med: den betales til ladeoperatøren. ` +
                       `Jordvarme og Øvrigt: elvarme-model, ${basisNote}, inkl. tariffer, elafgift og moms. ` +
                       (perDay > 0 ? `Abonnement: ${_fmt(perDay, 2)} kr/dag.` : 'Uden abonnement.');
            el.style.display = '';
        }
    }

    // Spot price context on the EV card (EV always uses the low elvarme rate)
    const evCard = document.getElementById('ev-dashboard-card');
    if (evCard && cost.low_rate > 0) {
        const cpkwhEl = evCard.querySelector('.dash-stat-cpkwh');
        if (cpkwhEl) cpkwhEl.title = `Spotpris gns. denne mdr: ${appUtils.num(cost.low_rate, 2)} kr/kWh (inkl. afgifter)`;
    }
}

async function _enrichSyncLabels() {
    try {
        const data = await Api.get('sync-status');
        if (!Array.isArray(data.sources)) return;

        const idMap = { monta: 'ev', heatpump: 'hp', housepowerlog: 'hus', hus: 'hus' };
        const now   = Date.now();
        _renderFreshnessAlert(data.freshness);
        // The server judges how old is too old (Freshness::MAX_AGE), per source.
        const staleIds = new Set((data.freshness?.checks || []).filter(c => c.status !== 'ok' && !c.optional).map(c => c.id));

        data.sources.forEach(src => {
            const cardPrefix = idMap[src.id];
            if (!cardPrefix) return;
            const el = document.getElementById(cardPrefix + '-sync-label');
            if (!el) return;

            if (!src.last_sync) { el.textContent = ''; return; }

            const syncMs  = new Date(src.last_sync.replace(' ', 'T')).getTime();
            const diffMin = Math.round((now - syncMs) / 60000);
            let label;
            if (diffMin < 2)       label = 'Data: lige nu';
            else if (diffMin < 60) label = `Data: ${diffMin}\u00a0min siden`;
            else if (diffMin < 120) label = 'Data: over 1 time gammel';
            else {
                const h = Math.round(diffMin / 60);
                label = `Data: ${h}\u00a0timer gammel`;
            }

            el.textContent  = label;
            el.className    = 'dash-sync' + (staleIds.has(src.id) ? ' dash-sync-stale' : '');
        });
    } catch (e) {
        // Best-effort — never surface errors
    }
}

/** A visible warning when a data source has stopped delivering (stale, errored or never seen). */
function _renderFreshnessAlert(freshness) {
    const box = document.getElementById('dash-freshness-alert');
    if (!box) return;
    const bad = (freshness?.checks || []).filter(c => c.status !== 'ok' && !c.optional);
    if (!bad.length) { box.style.display = 'none'; box.textContent = ''; return; }
    const age = sec => sec === null ? 'aldrig modtaget' : sec < 7200 ? `${Math.round(sec / 60)} min gammel` : sec < 172800 ? `${Math.round(sec / 3600)} timer gammel` : `${Math.round(sec / 86400)} dage gammel`;
    const items = bad.map(c => {
        const li = document.createElement('li');
        li.textContent = c.status === 'error' ? `${c.label}: fejl (${c.error})` : `${c.label}: ${age(c.age_sec)}`;
        return li;
    });
    const head = document.createElement('strong');
    head.textContent = 'Data er ikke frisk';
    const ul = document.createElement('ul');
    ul.className = 'mb-0 ps-3';
    items.forEach(li => ul.appendChild(li));
    box.replaceChildren(head, ul);
    box.style.display = '';
}

function _monthName() {
    const raw = new Date().toLocaleString('da-DK', { month: 'long', year: 'numeric' });
    return raw.charAt(0).toUpperCase() + raw.slice(1);
}

function _setText(parent, selector, text) {
    const el = parent.querySelector(selector);
    if (el) el.textContent = text;
}

// What the "Forventet" figure is based on (Projection::fullMonth -> projection_basis).
const _PROJECTION_BASIS = {
    last_year:  'Baseret p\u00e5 samme m\u00e5ned sidste \u00e5r, justeret efter tempoet hidtil i \u00e5r',
    prev_month: 'Baseret p\u00e5 forrige m\u00e5ned, justeret efter tempoet hidtil (sidste \u00e5r er ikke sammenligneligt)',
    run_rate:   'Baseret p\u00e5 forbruget hidtil i m\u00e5neden',
};
function _setProjectionBasis(parent, basis) {
    const el = parent.querySelector('.dash-projected');
    if (el) el.title = _PROJECTION_BASIS[basis] || '';
}

document.addEventListener('DOMContentLoaded', () => {
    // loadDashboard() is called lazily by SparkNav when Oversigt tab is first activated.
    // Re-fetch whenever a charge is saved so totals stay current.
    window.SparkEvents?.addEventListener('charge:saved', () => loadDashboard(true));

    // Coming back to the tab after a while: refresh right away instead of waiting
    // for the next 5-minute tick. _dashRefreshInterval is only set while Oversigt is active.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible' || !_dashRefreshInterval) return;
        if (Date.now() - _dashLastLoad > 120_000) loadDashboard(true);
    });
});

// =============================================================================
// Anomaly Detection
// =============================================================================

async function loadAnomalies() {
    try {
        const data = await Api.get('anomalies');
        if (!data.anomalies) return;

        _renderAnomalies(data.anomalies);
    } catch (e) {
        // Anomaly loading is best-effort — never surface errors to the user
        console.warn('loadAnomalies:', e);
    }
}

const _ANOMALY_SECTION = { ev: 'elbil-section', hp: 'jordvarme-section', hus: 'hus-section' };

/** Put the anomaly text at the top of the section the tab dot belongs to. */
function _renderSectionAnomalies(visible) {
    Object.entries(_ANOMALY_SECTION).forEach(([cat, sectionId]) => {
        const section = document.getElementById(sectionId);
        if (!section) return;
        section.querySelector(':scope > .section-anomalies')?.remove();
        const items = visible.filter(a => a.category === cat);
        const badge = document.getElementById('anomaly-badge-' + cat);
        if (badge) badge.title = items.map(a => a.title + ' — ' + (a.headline || '')).join(', ');
        if (!items.length) return;
        const box = document.createElement('div');
        box.className = 'section-anomalies';
        box.setAttribute('role', 'status');
        box.innerHTML = items.map(a => `
            <div class="section-anomaly ${appUtils.escapeHtml(a.severity)}">
              <i class="fas fa-exclamation-triangle"></i>
              <div><strong>${appUtils.escapeHtml(a.title)} — ${appUtils.escapeHtml(a.headline || (a.pct_above + '% over baseline'))}</strong>
              <div>${appUtils.escapeHtml(a.message)}</div></div>
            </div>`).join('');
        section.prepend(box);
    });
}

function _renderAnomalies(anomalies) {
    const panel    = document.getElementById('anomaly-panel');
    const listEl   = document.getElementById('anomaly-list');
    if (!panel || !listEl) return;

    // Only show warning/critical in the panel (suppress info)
    const visible = anomalies.filter(a => a.severity !== 'info');

    // Clear all tab badges first
    ['ev', 'hp', 'hus'].forEach(cat => {
        const el = document.getElementById('anomaly-badge-' + cat);
        if (el) el.style.display = 'none';
    });

    if (visible.length === 0) {
        panel.style.display = 'none';
        _renderSectionAnomalies([]);
        return;
    }

    // The dots in the tab bar and the banner on the section itself are always shown (even if the
    // overview panel is dismissed), so the reason is visible where the dot sends you.
    _renderSectionAnomalies(visible);

    // Update tab badge dots
    anomalies.forEach(a => {
        const el = document.getElementById('anomaly-badge-' + a.category);
        if (el && a.severity !== 'info') {
            el.className = 'anomaly-tab-badge ' + a.severity;
            el.style.display = '';
        }
    });

    // Check if user has dismissed these exact anomalies this month
    if (_isAnomalyDismissed(visible)) {
        panel.style.display = 'none';
        return;
    }

    // Colour the panel header based on worst severity
    const hasCritical = visible.some(a => a.severity === 'critical');
    panel.className = 'anomaly-panel' + (hasCritical ? ' has-critical' : '');

    const iconMap = {
        critical: '<i class="fas fa-times-circle"></i>',
        warning:  '<i class="fas fa-exclamation-triangle"></i>',
        info:     '<i class="fas fa-info-circle"></i>',
    };

    listEl.innerHTML = visible.map(a => {
        const navAttr = `data-action="nav" data-section="${appUtils.escapeHtml(a.nav_section)}"` +
            (a.nav_sub ? ` data-sub="${appUtils.escapeHtml(a.nav_sub)}"` : '');
        const causeHtml = a.possible_cause
            ? `<span class="anomaly-cause anomaly-cause-${a.possible_cause}">${a.possible_cause === 'vejr' ? '\u2601\ufe0f Vejret kan v\u00e6re \u00e5rsagen' : '\ud83d\udd0d Tjek forbrugsm\u00f8nster'}</span>`
            : '';
        return `
        <div class="anomaly-item">
          <div class="anomaly-item-icon ${a.severity}">${iconMap[a.severity]}</div>
          <div class="anomaly-item-body">
            <div class="anomaly-item-title">${appUtils.escapeHtml(a.title)} — ${appUtils.escapeHtml(a.headline || (a.pct_above + '% over baseline'))}</div>
            <div class="anomaly-item-msg">${appUtils.escapeHtml(a.message)}</div>
            ${causeHtml}
          </div>
          <button class="anomaly-item-action" ${navAttr}>Gå til →</button>
        </div>`;
    }).join('');

    // Add dismiss button
    let dismissBtn = panel.querySelector('.anomaly-dismiss-btn');
    if (!dismissBtn) {
        dismissBtn = document.createElement('button');
        dismissBtn.className = 'anomaly-dismiss-btn';
        dismissBtn.innerHTML = '<i class="fas fa-times"></i> Afvis';
        dismissBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            _dismissAnomalies(visible);
        });
        panel.appendChild(dismissBtn);
    } else {
        // Re-bind with current visible list
        dismissBtn.onclick = (e) => { e.stopPropagation(); _dismissAnomalies(visible); };
    }

    panel.style.display = '';
}

// =============================================================================
// Dashboard Auto-Refresh (5 minutes, only when Oversigt tab is active + visible)
// =============================================================================

function startDashboardAutoRefresh() {
    if (_dashRefreshInterval) return;
    _dashRefreshInterval = setInterval(() => {
        if (document.visibilityState === 'visible') {
            loadDashboard(true); // background refresh → show "Opdaterer…" indicator
        }
    }, 300_000); // 5 minutes
}

function stopDashboardAutoRefresh() {
    if (_dashRefreshInterval) {
        clearInterval(_dashRefreshInterval);
        _dashRefreshInterval = null;
    }
}

// =============================================================================
// Anomaly Dismiss — persistent until new month or severity change
// =============================================================================

function _getAnomalyDismissKey() {
    return 'anomaly_dismissed_' + _ymd().slice(0, 7);
}

function _isAnomalyDismissed(anomalies) {
    const key = _getAnomalyDismissKey();
    const dismissed = localStorage.getItem(key);
    if (!dismissed) return false;
    const currentSig = anomalies.map(a => a.category + ':' + a.severity).sort().join('|');
    return dismissed === currentSig;
}

function _dismissAnomalies(anomalies) {
    const key = _getAnomalyDismissKey();
    const sig = anomalies.map(a => a.category + ':' + a.severity).sort().join('|');
    localStorage.setItem(key, sig);
    const panel = document.getElementById('anomaly-panel');
    if (panel) panel.style.display = 'none';
    // Don't show "Ingen afvigelser" after dismiss — that would be misleading.
    // Tab badges remain visible as a subtle reminder.
}

// =============================================================================
// #5 — Sparkline Trend Detection
// =============================================================================

// The last point is today, which is only part over (a bar at noon is about half of what the day will be), so the
// trend compares the first and last 10 COMPLETE days.
function _sparklineTrend(sparkline) {
    if (!sparkline || sparkline.length < 20) return null;
    const done = sparkline.slice(0, -1);
    const first10 = done.slice(0, 10).reduce((s, v) => s + v, 0) / 10;
    const last10  = done.slice(-10).reduce((s, v) => s + v, 0) / 10;
    if (first10 === 0 && last10 === 0) return null;
    const pctChange = first10 > 0 ? ((last10 - first10) / first10) * 100 : (last10 > 0 ? 100 : 0);
    if (pctChange > 15)  return { label: '\u2197 Stigende', cls: 'trend-rising' };
    if (pctChange < -15) return { label: '\u2198 Faldende', cls: 'trend-falling' };
    return { label: '\u2192 Stabilt', cls: 'trend-stable' };
}

function _renderSparklineTrend(cardId, sparkline, caption = '', captionTitle = '') {
    const card = document.getElementById(cardId);
    if (!card) return;
    const container = card.querySelector('.dash-sparkline');
    if (!container) return;

    // Remove the previous foot row
    const existing = container.querySelector('.dash-spark-foot');
    if (existing) existing.remove();

    const trend = _sparklineTrend(sparkline);
    if (!trend && !caption) return;

    const foot = document.createElement('div');
    foot.className = 'dash-spark-foot';
    const cap = document.createElement('span');
    cap.className = 'dash-spark-caption';
    cap.textContent = caption;
    if (captionTitle) cap.title = captionTitle;
    foot.appendChild(cap);
    if (trend) {
        const el = document.createElement('span');
        el.className = 'dash-trend-micro ' + trend.cls;
        el.textContent = trend.label;
        foot.appendChild(el);
    }
    container.appendChild(foot);
}

// =============================================================================
// #4 — Season Normal Badge (normal/high/low vs. last year same period)
// =============================================================================

function _renderSeasonBadge(card, pctChangeYear) {
    if (!card || pctChangeYear === null) return;
    const dailyEl = card.querySelector('.dash-stat-daily');
    if (!dailyEl) return;

    // Remove existing badge
    const existing = dailyEl.querySelector('.dash-season-badge');
    if (existing) existing.remove();

    let cls, text;
    if (pctChangeYear > 30)       { cls = 'season-high'; text = 'over normalt'; }
    else if (pctChangeYear < -20) { cls = 'season-low';  text = 'under normalt'; }
    else return; // normal range — don't show badge

    const badge = document.createElement('span');
    badge.className = 'dash-season-badge ' + cls;
    badge.textContent = text;
    dailyEl.appendChild(badge);
}
