# Plan: teknisk gæld (to diagrambiblioteker)

Dato 2026-11-24. Udkast, intet er ændret i koden. Kilde: BACKLOG → Teknisk gæld ("To chart-biblioteker indlæses altid (L)").

## Hvad der er målt (kontrolleret mod koden)

**Chart.js** (13 diagrammer, 8 filer): `annual.js` 1, `app.js` 1, `baseload.js` 1, `dashboard.js` 1, `hus.js` 3, `jordvarme.js` 3, `regning.js` 2, `elbil/yearly.js` 1.
Hertil to plugins:
- `chartjs-adapter-date-fns`: ingen tidsakse i koden (ingen `type: 'time'`). Pakken er død vægt (ca. 50 KB).
- `chartjs-plugin-datalabels`: bruges kun af ét ringdiagram i `regning.js` (procenttal på skiverne). Alle andre 12 diagrammer slår det fra med `datalabels: { display: false }`, og det har givet to kendte nedbrud (`_listened`, omgået i `app.js` og `nav.js`).

**ApexCharts** (6 diagrammer, 4 filer, ca. 520 KB):
| Sted | Type |
|---|---|
| `dashboard.js` (elpris i dag) | søjler, hver sin farve, markeret billigste vindue |
| `elbil/analytics.js` (omkostningstendens) | stablet areal |
| `elbil/efficiency.js` (km/kWh, kr/km) | 2 linjer, datoakse |
| `elbil/tou.js` (elpris og ladninger pr. time/ugedag) | 2 heatmaps |

Apex medfører egen kode: `a11y.js` pakker Apex' SVG ind som ét billede, `app.js` oversætter Apex til dansk, og et XSS-hul i forklaringen (Apex renderer serienavne som HTML) er allerede rettet én gang.

**Bundlen:** alle fem scripts indlæses på hver side, også på dem uden diagrammer. Service workeren cacher dem (cache-first), så tabet er første besøg og hver hukommelsessession, ikke hvert besøg.

## Anbefaling: fjern ApexCharts, behold Chart.js

Chart.js har flest diagrammer og al den nyere kvalitetsindsats (tilgængelighedsplugin, serie­farver, markørformer, test). Det er mindre at flytte 6 diagrammer til Chart.js end 13 til Apex.

Eneste egentlige hul: **Chart.js har ingen heatmap**. De to heatmaps løses som en HTML-tabel (7 rækker × 24 kolonner, baggrund efter værdi). Det er et bedre svar til skærmlæsere end et SVG-billede, giver rigtige danske ugedage uden omvendt rækkefølge, og kræver intet bibliotek. Mørk tekst på cellen skal måles mod 4,5:1 som Regning-søjlerne.

## Trin (hvert trin kan rulles ud for sig; gammelt og nyt kan stå side om side)

| # | Trin | Størrelse | Gevinst / risiko |
|---|---|---|---|
| 1 | ✅ (2026-11-24) **Fjern `chartjs-adapter-date-fns`** (ingen bruger den). Verificér ved at slette script-linjen og køre alle sider med konsollen åben. Fjern også fra CSP/SW-notater i CHANGELOG. | S | ca. 50 KB, ingen risiko |
| 2 | ✅ (2026-11-24) **Dashboard elpris-søjler → Chart.js.** Distribuerede farver pr. søjle (`backgroundColor` som liste), "billigst"-vindue som plugin eller en farvet baggrund bag søjlerne, tooltip som i dag. | S–M | Det mindste Apex-sted, godt første skridt |
| 3 | ✅ (2026-11-24) **Omkostningstendens (stablet areal) → Chart.js** (`fill: true`, `stack`). Dato-kategorier som tekstakse, dedupliceringen bevares (grunden, Apex' A/B/C-suffikser, falder væk). | M | Seriefarver fra `appUtils.seriesColors()`/domænefarver |
| 4 | ✅ (2026-11-24) **Effektivitet (2 linjer med datoakse) → Chart.js.** Her har man brug for en tidsakse: enten adapteren igen (så trin 1 udgår) eller datoer som kategorier. **Foreslået: kategorier**, så adapteren kan blive væk. | M | Tjek at tomme dage ikke giver ujævn akse |
| 5 | **Heatmaps → HTML-tabeller** (`tou.js`). Ny lille hjælper `appUtils.heatTable(rows, cols, values, color, fmt)` med `<caption>`, `th scope`, tal i cellen (farven er en andendel, aldrig den eneste kode). Test: kontrast + ugedagenes rækkefølge Man→Søn. | M | Fjerner det sidste Apex-sted |
| 6 | **Fjern ApexCharts**: script-linjen, `app.js`' danske Apex-blok, Apex-grenen i `a11y.js`, XSS-noten, tests. | S | ca. 520 KB, ét tooltip-mønster, én a11y-sti |
| 7 | **Valgfrit: datalabels-plugin.** Ringdiagrammet i `regning.js` kan skrive procenttallet i forklaringen/tooltip i stedet. Så kan pluginet og de 12 `datalabels: { display: false }` samt nedbrudsomgangene i `app.js`/`nav.js` fjernes. | S–M | Mindre kode og færre kendte nedbrud; kun hvis procenterne på skiverne ikke skal bevares |
| 8 | **Indlæs kun det, siden bruger** (lazy-load Chart.js først ved en side med diagram; Dashboard har allerede sparklines). | M | Kun hvis der stadig er en side uden diagrammer; mål først |

Trin 1 kan rulles ud med det samme. Rækkefølgen 2 → 3 → 4 → 5 → 6 gør, at appen aldrig er i en halv tilstand: Apex bliver stående, indtil sidste bruger er flyttet.

## Hvordan hvert trin måles

1. Test med de eksisterende kontroller (`dev/test_a11y_js.js`, `test_datacolors.js`, `test_contrast.js`) og en ny `dev/test_charts_one_library.js`, som fejler, hvis `ApexCharts` optræder i kildekoden efter trin 6.
2. Se hver flyttet visning i browseren på 1280 og 375 px, og mod produktionskopien (rigtige data), ikke kun seed-data.
3. Mål sidevægt og Time to interactive før/efter (Network-fanen med slået cache fra).
4. CHANGELOG + BACKLOG pr. trin, commit pr. trin, produktion efter dit ja.

## Andre punkter, der er kommet frem (ikke en del af planen, men værd at kende)

- **Store filer:** `style.css` 2.428 linjer, `dashboard.js` 1.174, `jordvarme.js` 1.137, `index.php` 939. Ingen er akut; `jordvarme.js` og `dashboard.js` er de første kandidater til at deles op, hvis de skal ændres meget.
- **PHPStan** kører på niveau 4 med en baseline på 91 linjer (mest `doctor.php` og `AnnualSummary.php`). Kan strammes til niveau 5 trin for trin.
- **`docs/pending-patches`** har ét patch liggende (0001, ladninger parret via laderens egne sessioner). Afklar, om det skal bruges eller slettes.
- **Tredjepart:** kun `cdn.jsdelivr.net` er tilbage. Hvis trin 6 lykkes, kan Chart.js, Bootstrap og flatpickr egenhostes (versionerne er fastlåst med SRI), og CSP'en kan stramme til `script-src 'self'`. Det er et selvstændigt tema, ikke en del af denne plan.

## Beslutninger, jeg skal bruge fra dig

1. Skal heatmaps være HTML-tabeller (anbefalet) eller et Chart.js-plugin (`chartjs-chart-matrix`, endnu et bibliotek)?
2. Må efficiency-diagrammet bruge datoer som kategorier, så tidsadapteren forsvinder helt?
3. Skal procenttallene blive på ringdiagrammets skiver (så datalabels-pluginet bliver)?
