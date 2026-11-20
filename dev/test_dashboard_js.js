/**
 * Pure helpers of includes/dashboard.js that explain the cards' numbers. The file needs a browser, so the functions are cut out
 * of the real source and run in a bare VM.
 * Run:  node dev/test_dashboard_js.js
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

let n = 0, fails = 0;
function check(name, ok, info = '') {
    n++;
    if (ok) { console.log('  ok   ' + name); return; }
    fails++; console.log('  FAIL ' + name + ' ' + info);
}

const src = fs.readFileSync(path.join(__dirname, '..', 'includes', 'dashboard.js'), 'utf8');
function fn(name) {
    // the parameter list may hold parentheses (a default like `now = new Date()`), so match to ") {" on the signature line
    const m = src.match(new RegExp('(?:^|\\n)((?:async )?function ' + name + '\\(.*?\\) \\{[\\s\\S]*?\\n\\})\\n'));
    if (!m) throw new Error('function ' + name + ' not found in dashboard.js');
    return m[1];
}
const sandbox = vm.createContext({ Number, Math, Date });
vm.runInContext(['_fmt', '_hddExplanation', '_dayFraction', '_comparisonWindows', '_hddSum', '_weatherCorrectedPct', '_comparisonLabels', '_trendLine'].map(fn).join('\n') + '\n' +
    src.match(/const _MONTHS_SHORT = \[[^\]]*\];\nconst _MONTHS_LONG  = \[[^\]]*\];/)[0].replace(/^const /gm, 'var '), sandbox);
const why = sandbox._hddExplanation;

console.log('_hddExplanation(hddThis, hddLast, against)');
check('colder this year: says so, with the percentage (134.7 vs 97.6 = +38 %)',
    why(134.7, 97.6) === 'Koldere vejr end samme periode sidste år: 38 % flere gradedage', why(134.7, 97.6));
check('warmer this year: says so', why(70, 97.6) === 'Varmere vejr end samme periode sidste år: 28 % færre gradedage', why(70, 97.6));
check('about the same weather (under 10 %): no line', why(100, 97.6) === '' && why(90, 97.6) === '');
check('exactly 10 % is explained, 9 % is not', why(110, 100) !== '' && why(109, 100) === '');
check('no degree days last year (division by zero): no line, no exception', why(10, 0) === '' && why(0, 0) === '');
check('the percentage uses the Danish number format (2150 -> "2.150")', why(2250, 100).includes('2.150 %'), why(2250, 100));
check('it can name the stretch it compares with (last month by its dates)', why(200, 100, '1.–20. okt.') === 'Koldere vejr end 1.–20. okt.: 100 % flere gradedage', why(200, 100, '1.–20. okt.'));

console.log('\n_weatherCorrectedPct (kWh per degree day, now against then)');
const wc = sandbox._weatherCorrectedPct;
check('the same use in a colder stretch: use per degree day fell (kWh 100 and 100, degree days 150 and 100: -33 %)', Math.abs(wc(100, 150, 100, 100) - -33.3333) < 0.01, String(wc(100, 150, 100, 100)));
check('Jordvarme, 20 Nov: +99 % kWh against the mild October, double the degree days: about 0 % when the weather explains it', Math.abs(wc(178.4, 200, 89.48, 100.3)) < 1, String(wc(178.4, 200, 89.48, 100.3)));
check('the same use in the same weather: 0 %', wc(80, 100, 80, 100) === 0);
check('no degree days now or then, or nothing to divide: null, no exception', wc(80, 0, 80, 100) === null && wc(80, 100, 80, 0) === null && wc(80, 100, 0, 100) === null && wc(80, 100, null, 100) === null && wc(-1, 100, 80, 100) === null);
check('zero use now is a real answer (-100 %), not "no data"', wc(0, 100, 80, 100) === -100);

console.log('\n_comparisonWindows / _hddSum / _dayFraction (the weather matches how the server counts kWh)');
const win = d => sandbox._comparisonWindows(new Date(d));
const wv = win('2026-11-20T12:00:00');
check('20 Nov: 1.-20. Oct and 1.-20. Nov of last year', wv.prev.start === '2026-10-01' && wv.prev.end === '2026-10-20' && wv.year.start === '2025-11-01' && wv.year.end === '2025-11-20', JSON.stringify(wv));
check('31 Mar: February is cut at its 28th', win('2026-03-31T12:00:00').prev.end === '2026-02-28' && win('2026-03-31T12:00:00').year.end === '2025-03-31');
check('29 Feb 2028: last year has no 29th, so it is cut at the 28th (an unknown date is not asked for)', win('2028-02-29T12:00:00').year.end === '2027-02-28' && win('2028-02-29T12:00:00').prev.end === '2028-01-29');
check('January looks back to December of the year before', win('2027-01-15T12:00:00').prev.start === '2026-12-01' && win('2027-01-15T12:00:00').prev.end === '2026-12-15');
const recs = [{ date: '2026-11-18', hdd: 10 }, { date: '2026-11-19', hdd: 8 }, { date: '2026-11-20', hdd: 12 }];
check('the last day counts pro rata when it is only part over (10 + 8 + 12 x 0.5 = 24)', sandbox._hddSum(recs, '2026-11-20', 0.5) === 24);
check('a complete last day counts in full (the other month was shorter)', sandbox._hddSum(recs, '2026-11-20', 1) === 30);
check('no records: 0', sandbox._hddSum([], 'x', 0.5) === 0 && sandbox._hddSum(null, 'x', 0.5) === 0);
check('the share of the day is 5-100 %, like the server (midnight -> 5 %, noon -> 50 %, 23:59 -> about 100 %)',
    sandbox._dayFraction(new Date(2026, 10, 20, 0, 0)) === 0.05 && Math.abs(sandbox._dayFraction(new Date(2026, 10, 20, 12, 0)) - 0.5) < 1e-9 && sandbox._dayFraction(new Date(2026, 10, 20, 23, 59)) > 0.99);

console.log('\n_trendLine: nothing is not a rise');
const tlf = (a, b, tone) => sandbox._trendLine('kWh', a, b, tone, '', new Date('2026-11-20T12:00:00'));
check('a change that rounds to zero reads "→0 %", neutral, also for money lines', tlf(0.3, -0.4, 'cost').includes('→0 %') && !tlf(0.3, -0.4, 'cost').includes('trend-up') && !tlf(0.3, -0.4, 'cost').includes('trend-down') && !/[↑↓]/.test(tlf(0.3, -0.4, 'cost')), tlf(0.3, -0.4, 'cost'));
check('a real change keeps its arrow', tlf(7.6, -12.2, 'neutral').includes('↑8 %') && tlf(7.6, -12.2, 'neutral').includes('↓12 %'));
check('the row can carry a tooltip', sandbox._trendLine('Vejrkorr.', 5, 5, 'neutral', '', new Date('2026-11-20T12:00:00'), 'Justeret').includes('title="Justeret"'));

console.log('\n_sharePercents / _costShareBars');
const shares = vm.createContext({ Number, Math });
vm.runInContext(fn('_fmt') + '\n' + fn('_sharePercents') + '\n' + fn('_costShareBars') + '\n' +
    src.match(/const _SHARE_PARTS = \[[\s\S]*?\];\nconst _SHARE_SUB_COLOR = '[^']+';/)[0].replace(/^const /gm, 'var '), shares);
const pct = shares._sharePercents, bars = shares._costShareBars;
const sum = a => a.reduce((s, v) => s + v, 0);
check('percents add up to exactly 100 (thirds: 34/33/33)', sum(pct([1, 1, 1])) === 100 && pct([1, 1, 1]).join() === '34,33,33', pct([1, 1, 1]).join());
check('the dashboard figures (389/178/250 kWh) give 48/22/30 % (sums to 100)', pct([388.73, 178.4, 249.78]).join() === '48,22,30', pct([388.73, 178.4, 249.78]).join());
check('nothing, negatives and NaN count as zero', pct([0, 0]).join() === '0,0' && pct([-5, 10, NaN]).join() === '0,100,0');
const html = bars({ ev: 388.73, hp: 178.4, rest: 249.78 }, { ev: 1036, hp: 752, rest: 1141, sub: 105 });
check('two bars and a legend', (html.match(/class="tc-row"/g) || []).length === 2 && html.includes('class="tc-legend"'), html.slice(0, 80));
check('kWh bar has three segments, kr bar four (with the subscription)', (html.match(/class="tc-track"[\s\S]*?<\/div><\/div>/g) || []).map(s => (s.match(/tc-seg/g) || []).length).join() === '3,4');
check('segment widths of a bar add up to 100 %', (() => {
    const bar = html.match(/class="tc-track"[\s\S]*?<\/div><\/div>/)[0];
    return Math.abs(sum([...bar.matchAll(/width:([\d.]+)%/g)].map(m => +m[1])) - 100) < 0.05;
})());
check('the legend sets the kWh share beside the kr share (EV 48 % of kWh, 34 % of kr)', html.includes('EV hjemme <b>48 %</b> af kWh · <b>34 %</b> af kr'), html.match(/EV hjemme <b>[^<]*<\/b>[^<]*<b>[^<]*<\/b>/)?.[0]);
check('each bar is described for screen readers', html.includes('role="img" aria-label="kWh hidtil 817: EV hjemme 48 %, Jordvarme 22 %, Øvrigt 30 %"'), html.match(/aria-label="[^"]*"/)?.[0]);
check('no subscription: the kr bar has three segments', (bars({ ev: 1, hp: 1, rest: 1 }, { ev: 1, hp: 1, rest: 1, sub: 0 }).match(/tc-seg/g) || []).length === 6);
check('no kWh or no kr: no bars', bars({ ev: 0, hp: 0, rest: 0 }, { ev: 1, hp: 1, rest: 1, sub: 0 }) === '' && bars({ ev: 1, hp: 1, rest: 1 }, { ev: 0, hp: 0, rest: 0, sub: 0 }) === '');

console.log('\n_sparklineTrend / _sparkBarColors (today is only part over)');
const spark = vm.createContext({ Number, Math });
vm.runInContext(fn('_sparklineTrend') + '\n' + fn('_sparkBarColors'), spark);
const trend = spark._sparklineTrend;
const flat29 = Array(29).fill(10);
check('a steady series with a half-finished today is "Stabilt", not "Faldende"',
    trend([...flat29, 5.3]).label.includes('Stabilt'), JSON.stringify(trend([...flat29, 5.3])));
check('the same today bar being huge changes nothing either', trend([...flat29, 90]).label.includes('Stabilt'));
check('a real rise over the finished days is still found', trend([...Array(10).fill(5), ...Array(10).fill(7), ...Array(10).fill(10)]).label.includes('Stigende'));
check('a real fall is still found', trend([...Array(10).fill(10), ...Array(10).fill(7), ...Array(10).fill(5)]).label.includes('Faldende'));
check('too short a series, or nothing at all: no trend', trend([1, 2, 3]) === null && trend(null) === null && trend(Array(30).fill(0)) === null);
const colors = spark._sparkBarColors(4, '#22c55e');
check('only the last bar (today) is pale', colors.join() === '#22c55eaa,#22c55eaa,#22c55eaa,#22c55e33', colors.join());
check('one bar: it is today, so pale', spark._sparkBarColors(1, '#fff')[0] === '#fff33' && spark._sparkBarColors(0, '#fff').length === 0);

console.log('\n_evSplitShares / _evDriveLine (the EV card)');
const evc = vm.createContext({ Number, Math });
vm.runInContext(fn('_fmt') + '\n' + fn('_sharePercents') + '\n' + fn('_evSplitShares') + '\n' + fn('_evDriveLine'), evc);
const ns = s => s.replace(/ /g, ' ');
const sh = evc._evSplitShares({ home_kwh: 388.73, ext_kwh: 32.37, home_cost: 736.24, ext_cost: 142.03 });
check('away charging: 8 % of the kWh but 16 % of the kr (November)', sh.extKwhPct === 8 && sh.extKrPct === 16 && sh.hasKr, JSON.stringify(sh));
check('only home charging: 0 % away on both', (s => s.extKwhPct === 0 && s.extKrPct === 0)(evc._evSplitShares({ home_kwh: 50, ext_kwh: 0, home_cost: 90, ext_cost: 0 })));
check('no kWh at all: no split', evc._evSplitShares({ home_kwh: 0, ext_kwh: 0, home_cost: 0, ext_cost: 0 }) === null);
check('kWh but no prices: the kr share is not made up', evc._evSplitShares({ home_kwh: 10, ext_kwh: 5, home_cost: 0, ext_cost: 0 }).hasKr === false);
const drive = evc._evDriveLine({ drive_kr_per_km_30d: 0.44, drive_km_per_kwh_30d: 3.93, drive_km_30d: 2790, drive_confidence: 'høj', drive_why: 'mindst 300 kWh' });
check('drive line: kr/km and km/kWh, no caveat when sure', drive.text === 'Kørsel, 30 dage: 0,44 kr/km · 3,9 km/kWh', drive.text);
check('its tooltip says how many km, and that it is the Effektivitet calculation', ns(drive.title).startsWith('2.790 km') && drive.title.includes('Effektivitet'), drive.title);
check('not "høj": says it is uncertain', evc._evDriveLine({ drive_kr_per_km_30d: 0.5, drive_km_per_kwh_30d: 4, drive_km_30d: 80, drive_confidence: 'lav' }).text.endsWith('(usikkert)'));
check('no price per km: no line', evc._evDriveLine({ drive_kr_per_km_30d: null }) === null && evc._evDriveLine({}) === null);

console.log('\n_husComposition (the Hus card\'s bar)');
const hc = vm.createContext({ Number, Math });
vm.runInContext(fn('_fmt') + '\n' + fn('_sharePercents') + '\n' + fn('_husComposition') + '\n' +
    src.match(/const _SHARE_PARTS = \[[\s\S]*?\];\nconst _SHARE_SUB_COLOR = '[^']+';/)[0].replace(/^const /gm, 'var '), hc);
const comp = hc._husComposition({ month_kwh: 816.91, ev_home_kwh: 388.73, hp_kwh: 178.4 }, { month_kwh: 249.78 });
check('the three shares of the meter: 48 / 22 / 30 %, adding up to 100', comp.legend.includes('EV hjemme <b>48&nbsp;%</b>') && comp.legend.includes('Jordvarme <b>22&nbsp;%</b>') && comp.legend.includes('Øvrigt <b>30&nbsp;%</b>'), comp.legend);
check('the caption is the house meter\'s kWh so far', comp.caption === 'Husmåler hidtil: 817 kWh', comp.caption);
check('segment widths add up to 100 %', Math.abs([...comp.track.matchAll(/width:([\d.]+)%/g)].reduce((s, m) => s + +m[1], 0) - 100) < 0.05, comp.track);
check('the segments carry the card colours (blue, green, amber)', comp.track.includes('#6BA3FF') && comp.track.includes('#22c55e') && comp.track.includes('#f59e0b'));
check('each segment has a tooltip with kWh and share', comp.track.includes('title="Øvrigt: 250 kWh (30 %)"'), comp.track);
check('the card\'s own part (Øvrigt) is marked, the others are not', comp.legend.includes('dash-comp-key dash-comp-this"><i class="dash-comp-dot" style="background:#f59e0b">') && (comp.legend.match(/dash-comp-this/g) || []).length === 1);
check('screen readers get the whole picture', comp.label === 'Husmåler hidtil: 817 kWh: EV hjemme 48 %, Jordvarme 22 %, Øvrigt 30 %', comp.label);
check('no house meter (hus null) or no rest: no bar', hc._husComposition(null, { month_kwh: 1 }) === null && hc._husComposition({ month_kwh: 5, ev_home_kwh: 1, hp_kwh: 1 }, null) === null);
check('nothing used: no bar, no division by zero', hc._husComposition({ month_kwh: 0, ev_home_kwh: 0, hp_kwh: 0 }, { month_kwh: 0 }) === null);
check('missing numbers count as 0', hc._husComposition({ month_kwh: 10, ev_home_kwh: undefined, hp_kwh: null }, { month_kwh: 10 }).legend.includes('Øvrigt <b>100&nbsp;%</b>'));

console.log('\n_comparisonLabels / _trendLine (the dates compared, and red only for money)');
const cl = vm.createContext({ Number, Math, Date });
vm.runInContext(fn('_fmt') + '\n' + fn('_comparisonLabels') + '\n' + fn('_trendLine') + '\n' +
    src.match(/const _MONTHS_SHORT = \[[^\]]*\];\nconst _MONTHS_LONG  = \[[^\]]*\];/)[0].replace(/^const /gm, 'var '), cl);
const lab = d => cl._comparisonLabels(new Date(d));
check('20 Nov 2026: the same days of October and of November 2025, and all of October',
    lab('2026-11-20T12:00:00').prev === '1.–20. okt.' && lab('2026-11-20T12:00:00').year === '1.–20. nov. 2025' && lab('2026-11-20T12:00:00').prevMonth === 'oktober', JSON.stringify(lab('2026-11-20T12:00:00')));
check('the 1st is one day, not "1.–1."', lab('2026-11-01T12:00:00').prev === '1. okt.' && lab('2026-11-01T12:00:00').year === '1. nov. 2025');
check('31 Mar: February is cut at its 28th, as the server does', lab('2026-03-31T12:00:00').prev === '1.–28. feb.' && lab('2026-03-31T12:00:00').year === '1.–31. mar. 2025', JSON.stringify(lab('2026-03-31T12:00:00')));
check('29 Feb 2028: last year (2027) has no 29th, so it is cut at the 28th', lab('2028-02-29T12:00:00').year === '1.–28. feb. 2027' && lab('2028-02-29T12:00:00').prev === '1.–29. jan.', JSON.stringify(lab('2028-02-29T12:00:00')));
check('January looks back to December of the year before', lab('2027-01-15T12:00:00').prev === '1.–15. dec.' && lab('2027-01-15T12:00:00').prevMonth === 'december');
check('May has no dot (it is not an abbreviation)', lab('2026-06-10T12:00:00').prev === '1.–10. maj' && lab('2026-06-10T12:00:00').prevMonth === 'maj');
const tl = cl._trendLine('kWh', 99.4, 14.1, 'neutral', '', new Date('2026-11-20T12:00:00'));
check('the trend line names the dates, not "forrige mdr"', tl.includes('vs. 1.–20. okt. ↑99 %') && tl.includes('vs. 1.–20. nov. 2025 ↑14 %') && !tl.includes('forrige mdr'), tl);
check('kWh lines are neutral: no red, no green', !tl.includes('trend-up') && !tl.includes('trend-down') && tl.includes('trend-neutral-dir'));
const money = cl._trendLine('Pris', 57.9, -3, 'cost', '', new Date('2026-11-20T12:00:00'));
check('money lines: a rise is red, a fall is green', money.includes('trend-up') && money.includes('trend-down'), money);
check('nothing to compare with: no line', cl._trendLine('kWh', null, undefined, 'neutral') === '');

console.log('\n_sparkStats / _sparkCaption / _evSparkCaption (the scale of a sparkline)');
const sp = vm.createContext({ Number, Math });
vm.runInContext(fn('_fmt') + '\n' + fn('_sparkStats') + '\n' + fn('_sparkCaption') + '\n' + fn('_evSparkCaption') + '\n' + fn('_evSparkTitle'), sp);
const st = sp._sparkStats([4, 6, 8, 5.3]);
check('mean of the finished days, and today so far', st.avg === 6 && st.today === 5.3, JSON.stringify(st));
check('a half-finished today does not pull the mean down', sp._sparkStats([10, 10, 10, 0.5]).avg === 10);
check('one point only (today): no mean', sp._sparkStats([3]).avg === null && sp._sparkStats([]).avg === null && sp._sparkStats(null).avg === null);
check('the caption gives mean and today in Danish format', sp._sparkCaption([9, 9.4, 9, 5.3], 'kWh/dag') === 'Gns. 9,1 kWh/dag · i dag hidtil 5,3', sp._sparkCaption([9, 9.4, 9, 5.3], 'kWh/dag'));
check('no finished day: no caption', sp._sparkCaption([5], 'kWh/dag') === '');
const ev30 = Array.from({ length: 30 }, (_, i) => i === 0 ? 6.1 : i === 29 ? 9.8 : 8);
check('EV caption: the 7-day mean now, short enough to share the row with the trend word', sp._evSparkCaption(ev30) === '7-dages snit: 9,8 kWh/dag');
check('EV caption tooltip: where it was 30 days ago', sp._evSparkTitle(ev30).startsWith('For 30 dage siden: 6,1 kWh/dag'), sp._evSparkTitle(ev30));
check('EV caption: no series, no caption or tooltip', sp._evSparkCaption([]) === '' && sp._evSparkCaption(null) === '' && sp._evSparkTitle([]) === '');

console.log('\n' + n + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
