/**
 * Browser fingerprint of the Elbil tab, for proving that a frontend refactoring changed nothing.
 *
 * Usage (the test instance, see README "Lokal testinstans"):
 *   1. Open http://localhost:8080/#elbil/ladninger and paste this file into the console
 *      (or the browser tool) - it defines window.__cap.
 *   2. Run the stages one after the other (each stays under ~40 s):
 *        await __cap.s1(); await __cap.s2(); ... await __cap.s6();  const r = await __cap.result();
 *   3. Save r BEFORE the refactoring; after it, reload, repeat and compare the two objects.
 *
 * It records short SHA-256 fingerprints (+ text length) of: the charge table, summary and pie/Chart.js
 * data in the default view, with a vehicle filter, the quick filters, the zero-kWh toggle and every
 * sort column; the Analyse tab (optimisation, efficiency, cost statistics) and the comparison tab;
 * the Apex chart texts; the manage modal. The data is relative to today, so a recording is only
 * comparable on the day it was made (and on the same seeded database).
 */
window.__cap = (() => {
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const sha = async t => [...new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(t)))].map(b => b.toString(16).padStart(2, '0')).join('').slice(0, 10);
  const txt = sel => (document.querySelector(sel)?.innerText || document.querySelector(sel)?.textContent || '').replace(/\s+/g, ' ').trim();
  const html = sel => (document.querySelector(sel)?.innerHTML || '').replace(/\s+/g, ' ').replace(/ (id|data-bs-original-title|aria-describedby)="[^"]*"/g, '');
  const chartJs = () => Object.values(Chart.instances || {}).map(c => c.config.type + ':' + (c.canvas?.id || '') + ':' + JSON.stringify(c.data.labels || []).slice(0, 300) + JSON.stringify(c.data.datasets.map(d => d.data))).sort().join('|');
  const apexTxt = () => ['#kmPerKwhChart', '#krPerKmChart', '#costTrendChart', '#touHeatmapContainer', '#touPriceChart', '#evYearChart', '#evYearChips'].map(s => s + '=' + txt(s)).join('|');
  const set = (id, v) => { const e = document.getElementById(id); e.value = v; e.dispatchEvent(new Event('change', { bubbles: true })); };
  const snap = {};
  const take = (label, parts) => { for (const [k, v] of Object.entries(parts)) snap[label + '.' + k] = v; };
  const tab = async (sub) => { document.querySelector(`#elbil-section [data-sub="${sub}"]`).click(); await sleep(2200); };
  return {
    async s1() {
      document.querySelector('[data-target="elbil-section"]').click(); await sleep(2200);
      await tab('elbil-ladninger');
      take('def.ladninger', { summary: txt('#summaryText'), rows: txt('#chargeTableBody'), rowCount: document.querySelectorAll('#chargeTableBody tr').length, head: txt('#chargeTableHead'), filterOpts: txt('#filter') });
      await tab('elbil-analyse');
      take('def.analyse', { opt: txt('#chargeOptContent'), eff: html('#efficiencySummary'), costStats: txt('#costStatsContent') });
      await tab('elbil-sammenligning'); await sleep(2500);
      take('def.sammenligning', { all: txt('#elbil-sammenligning') });
      take('def.charts', { chartjs: chartJs(), apex: apexTxt() });
    },
    async s2() {
      document.getElementById('manageBtn').click(); await sleep(1500);
      take('manage', { vehicles: html('#manageVehiclesList'), providers: html('#manageProvidersList') });
      document.querySelector('.modal.show [data-bs-dismiss="modal"], .modal.show .btn-close')?.click(); await sleep(800);
      await tab('elbil-ladninger');
      set('filter', '1'); await sleep(2500);
      take('veh1.ladninger', { summary: txt('#summaryText'), rows: txt('#chargeTableBody'), rowCount: document.querySelectorAll('#chargeTableBody tr').length });
      await tab('elbil-analyse');
      take('veh1.analyse', { eff: html('#efficiencySummary'), costStats: txt('#costStatsContent') });
      take('veh1.charts', { chartjs: chartJs(), apex: apexTxt() });
    },
    async s3() {
      await tab('elbil-ladninger');
      set('filter', 'all'); await sleep(1500);
      for (const q of ['month', 'lmonth']) {
        set('quickFilter', q); await sleep(2200);
        take('quick-' + q, { summary: txt('#summaryText'), rowCount: document.querySelectorAll('#chargeTableBody tr').length, rows: txt('#chargeTableBody').slice(0, 3000), range: document.getElementById('dateRange').value });
      }
    },
    async s4() {
      set('quickFilter', 'year'); await sleep(2200);
      take('quick-year', { summary: txt('#summaryText'), rowCount: document.querySelectorAll('#chargeTableBody tr').length, range: document.getElementById('dateRange').value });
      set('quickFilter', 'all'); await sleep(2200);
      const z = document.getElementById('showZeroKwh'); z.checked = true; z.dispatchEvent(new Event('change', { bubbles: true })); await sleep(2200);
      take('zero', { rowCount: document.querySelectorAll('#chargeTableBody tr').length, summary: txt('#summaryText') });
      z.checked = false; z.dispatchEvent(new Event('change', { bubbles: true })); await sleep(2200);
    },
    async s5() {
      let i = 0;
      for (const col of ['kwh', 'pris', 'vehicle', 'datetime', 'datetime']) {
        document.querySelector(`#chargeTableHead th[data-sort="${col}"]`).click(); await sleep(400);
        take('sort-' + (i++) + '-' + col, { rows: txt('#chargeTableBody').slice(0, 800) });
      }
      await tab('elbil-analyse');
      set('chargeOptMonths', '12'); await sleep(2200);
      take('opt12', { opt: txt('#chargeOptContent') });
    },
    async s6() {
      await tab('elbil-sammenligning');
      document.querySelector('[data-action="vehicle-comparison-sort"][data-sort="total_kwh"]')?.click(); await sleep(1500);
      take('cmp-sort', { text: txt('#elbil-sammenligning').slice(0, 4000), apex: apexTxt() });
    },
    async result() {
      const out = {};
      for (const [k, v] of Object.entries(snap)) out[k] = (typeof v === 'number' ? 'n=' + v : (await sha(String(v))) + '/' + String(v).length);
      return out;
    },
  };
})();
