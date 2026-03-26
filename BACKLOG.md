# SparkSpend — Product Backlog

Generated: 2026-03-11 · Last updated: 2026-03-16
Covers all features across Oversigt, Elbil, Jordvarme, Hus, architecture, and UX.
Items within each priority tier are ordered by impact.

---

## Afsluttet (siden oprettelse)

- ✅ **BUG — April/Juli 2025 husforbrug viste ~33M / ~36M kWh** — Datalogger gemte Wh i stedet for kWh. Fix: LAG-baseret daglig delta (i stedet for MAX-MIN) i alle kumulerede måler-queries (`getMonthlyBillData.php`, `getHeatpumpData.php`, `getDashboardSummary.php`). Wh→kWh normalisering under sync.
- ✅ **FEATURE — Hus-sektion** — Nyt topniveau-faneblad med to under-faner: *Forbrug* (stablet søjlediagram med Jordvarme/El-bil/Restforbrug opdeling, daglig/månedlig/sammenligning/år-til-dato tilstande) og *Regning* (månedlig fakturaoversigt).
- ✅ **FEATURE — Hus synkronisering** — `cron/sync_housepowerlog_data.php` synkroniserer MySQL `powerloghus` → SQLite med Wh→kWh normalisering (threshold > 100.000).
- ✅ **FEATURE — Hus-kort på Oversigt** — Tredje dashboard-kort med sparkline (amber), kWh/dag, forventet månedforbrug og trend-badges.
- ✅ **ENHANCEMENT — Session-timeout → automatisk login-redirect** — Global `fetch`-interceptor i `app.js` detekterer 401/403 og transparente auth-proxy-redirects (302 → HTML). Viser banner og reloader siden efter 2 sek.
- ✅ **ENHANCEMENT — Konsekvente farver på tværs af sektioner** — Hus-sektionens søjlediagram bruger nu de samme farver som dashboard-kortene: grøn (Jordvarme), blå (El-bil), amber (Restforbrug).
- ✅ **BUG — `ext_charges.datetime` gemt som pseudo-UTC (lokal tid + Z-suffix)** — `createExCharge.php` og `updateExtCharge.php` tilføjede nu `->setTimezone(new DateTimeZone('UTC'))` inden `format()`, så det gemte tidsstempel er ægte UTC. Opladninger tæt på midnat tilknyttes nu korrekt dato.
- ✅ **ENHANCEMENT — Jordvarme estimeret kr-pris (alle 4 tilstande)** — `activeCostMap` + `fetchElCosts()` i `jordvarme.js` henter spotpris + nettarif + systemtarif + elafgift + moms fra `getElspotPrices.php`. Alle fire tilstande (daglig, månedlig, sammenligning, ÅTD) viser "Estimeret elomkostning" / "Est. kr"-kolonne.
- ✅ **BUG — `provideres` tabel/filnavn-typo** — SQLite-tabel omdøbt `provideres` → `providers` via `cron/migrate_providers_rename.php`. Ny `getProviders.php` oprettet, `elbil.js`, `QueryBuilder.php` og `getProviderStats.php` opdateret.
- ✅ **ENHANCEMENT — Dashboard lazy-init + chart.resize** — `loadDashboard()` flyttes til første besøg på Oversigt-fanebladet i `nav.js`. `_resizeSection()` med `requestAnimationFrame` kaldes på alle faner ved hvert tab-skift, så Chart.js-instanser aldrig er tegnet ved 0×0.
- ✅ **BUG — `_enrichHpWithWeather` brugte localStorage** — Vejrkorrigerings-funktion i `dashboard.js` læste `lat/lon` fra `localStorage` (ryddet ved load af jordvarme.js). Fikset til `window.sparkConfig.weatherLat/weatherLon`.
- ✅ **ENHANCEMENT — Vehicle management UI** — "Administrér"-knap i Elbil sub-nav åbner modal med inline omdøbning af køretøjer. `updateVehicle.php` + `QueryBuilder::updateVehicle()` tilføjet.
- ✅ **ENHANCEMENT — Provider management UI** — Samme modal. Udbydere kan oprettes, omdøbes og slettes. `createProvider.php`, `updateProvider.php`, `deleteProvider.php` + tilhørende QueryBuilder-metoder. Sletning afvises hvis udbyderen er i brug.
- ✅ **DEBT — Monta vehicleName auto-opdatering** — `QueryBuilder::upsertVehicle()` tilføjet. `receive_vehicle_data.php` accepterer nu valgfrit `vehicleName`-felt og upsert'er `vehicles`-tabellen. `cron/update_monta_data.php` checker API-responsen for vehicle-navnefelter og kalder upsert efter hvert match.
- ✅ **ENHANCEMENT — EV opladningssession effekt (kW) + varighed** — `getCharges.php` returnerer nu `startedAt` + `stoppedAt` for interne ladninger. `_renderCharges` beregner varighed og Ø-kW og viser dem i ny "Varighed"-kolonne (`1h 23m` / `Ø 7.2 kW`).
- ✅ **DEBT — Output-caching på `getDashboardSummary.php` + `getChargeAnalytics.php`** — Filbaseret cache i `/tmp`. `getDashboardSummary.php` bruger 60s TTL med daglig cache-nøgle. `getChargeAnalytics.php` bruger 300s TTL med md5-nøgle på alle GET-parametre.
- ✅ **ENHANCEMENT — Årsrapportvisning** — Nyt topniveau-faneblad "Årsrapport" (`#aarsrapport`). `getAnnualSummary.php` aggregerer EV (intern + ekstern), jordvarme og hus pr. kalenderår. `includes/annual.js` renderer grupperet søjlediagram + tabel med EV ladninger, kWh, pris, VP kWh og Hus total kWh. 1-times filcache.
- ✅ **ENHANCEMENT — Global sync-statusindikator + "Sync nu"-knap** — `getSyncStatus.php` forespørger `sync_log` (jordvarme/hus) + `MAX(stoppedAt)` (Monta). `triggerSync.php` kører cron-scripts i baggrunden. ⚙-knap i headeren åbner `#syncModal` med OK/Fejl-badge, relativ tid, per-kilde sync-knapper og "Sync alle".

---

## Næste Sprint (forslag) — se nedenfor

---

## Priority: High

### ~~BUG — `provideres` table/filename typo er fastfrosset i schema~~ ✅ Rettet
~~SQLite-tabellen hedder `provideres`, endpointet er `getProvideres.php`, og `QueryBuilder::selectAllProviders()` forespørger den. En engangs-migration (`ALTER TABLE provideres RENAME TO providers`) plus fil/reference-omdøbning ville løse det permanent. Vedligeholder sig selv på tværs af alle kaldere.~~

### ~~BUG — `ext_charges.datetime` gemt som pseudo-UTC (lokal tid + Z-suffix)~~ ✅ Rettet

### ~~ENHANCEMENT — Jordvarme estimeret kr-pris mangler~~ ✅ Rettet
~~Den sofistikerede elpriskalkulation (`getElspotPrices.php`) eksisterer og beregner korrekt spot + nettarif + systemtarif + elafgift + moms for et givet datointerval, men den bruges aldrig på varmepumpedata. Brugere ser kun kWh, ikke kr. Tilføjelse af "Estimeret pris" i alle fire Jordvarme-tilstande (daglig, månedlig, sammenligning, ÅTD) ved brug af `getElspotPrices.php` ville lukke det mest oplagte informationshul i appen.~~

### ~~ENHANCEMENT — Vehicle management UI~~ ✅ Rettet
~~Køretøjer eksisterer i SQLite men kan kun oprettes/omdøbes via direkte databasemanipulation.~~ "Administrér"-modal tilføjet i Elbil sub-nav. Inline omdøbning via `updateVehicle.php`.

### ~~ENHANCEMENT — Provider management UI~~ ✅ Rettet
~~Samme problem som køretøjer: udbydere kan kun tilføjes via direkte DB-adgang.~~ Fuld CRUD i samme "Administrér"-modal: opret (`createProvider.php`), omdøb (`updateProvider.php`), slet (`deleteProvider.php` — blokerer sletning hvis udbyderen er i brug).

### ~~ENHANCEMENT — Dashboard genlæser ved settings-ændring~~ ✅ Rettet (delvist N/A)
~~Når brugeren opdaterer lat/lon i settings-modalen,~~ Settings-modalen er fjernet — alle indstillinger er nu server-side via `.env`. Sideeffekt rettet: `_enrichHpWithWeather` i `dashboard.js` læste stadig fra `localStorage` (som ryddes ved load), og vejrkorrigering virkede aldrig. Fikset til at bruge `window.sparkConfig.weatherLat/weatherLon`.

---

## Priority: Medium

### ~~ENHANCEMENT — Dashboard lazy-init når Oversigt ikke er aktiv ved load~~ ✅ Rettet
~~`loadDashboard()` fyres ved `DOMContentLoaded` uden betingelser.~~ `loadDashboard()` fjernet fra `DOMContentLoaded` i `dashboard.js`. `nav.js` kalder det nu ved første besøg på Oversigt-fanebladet. `_resizeSection()` kaldes med `requestAnimationFrame` på alle faner ved hvert tab-skift for at rette 0×0-charts.

### ENHANCEMENT — EV hjemmeladning kr fra spotpris
Aktuelt tages EV-hjemmeladningsomkostninger direkte fra Monta API's `cost`-felt. Monta's pris kan afspejle en fast tarif eller en unøjagtig sats. Den eksisterende `getElspotPrices.php`-infrastruktur kunne i stedet beregne den reelle elomkostning for hjemmeladningssessioner (spot + tariffer), hvilket giver brugeren nøjagtig og transparent prissætning — samme metodologi som varmepumpen.
**Filer:** `getCharges.php` eller ny `getHomeChargeCost.php`, `includes/elbil.js`

### ENHANCEMENT — EV flerårigt sammenligningsvisning (spejler Jordvarme)
Jordvarme-fanebladet har en `Sammenligning`-tilstand der overlapper flere års månedlige forbrug på ét diagram. EV-fanebladet har køretøjssammenligning men ingen tilsvarende år-over-år oversigt over opladningsmønstre på tværs af kalenderår.
**Filer:** `includes/elbil.js`, ny `getChargesByYear.php` eller udvid `getChargeAnalytics.php`

### ~~ENHANCEMENT — Opladningssession effekt (kW) og varighed~~ ✅ Rettet
~~Monta API sender `cablePluggedInAt`, `startedAt` og `stoppedAt`. Sessionvarighed og gennemsnitlig effekt beregnes aldrig.~~ Ny "Varighed"-kolonne i opladningstabellen viser `1h 23m` og `Ø X.X kW` for interne ladninger; externe ladninger viser `—`.

### ENHANCEMENT — CSV/Excel-eksport af opladningshistorik
Ingen eksportfunktionalitet eksisterer nogen steder i appen. En "Eksporter CSV"-knap på opladningstabellen og en månedlig sammendragseksport på Jordvarme-fanebladet ville opfylde grundlæggende dataportabilitetsforventninger.
**Filer:** ny `exportCharges.php`, ny `exportHeatpump.php`, `includes/elbil.js`, `includes/jordvarme.js`

### ~~ENHANCEMENT — Global sync-statusindikator~~ ✅ Rettet
~~`cron/cron.log` viser seneste synkroniseringstidspunkt og eventuelle fejl, men er kun læsbar via SSH.~~ Ny `getSyncStatus.php` forespørger `sync_log`-tabellen i SQLite (jordvarme/hus) og `MAX(stoppedAt)` fra charges (Monta). ⚙-knap i headeren åbner `#syncModal` med OK/Fejl-badge og relativ tid per kilde.

### ~~ENHANCEMENT — "Sync nu"-knap for Monta og varmepumpe~~ ✅ Rettet
~~Aktuelt opdateres begge datakilder kun via planlagt cron.~~ Ny `triggerSync.php` kører cron-scripts i baggrunden via `PHP_BINARY exec()`. Samme sync-modal har "Sync alle"-knap + individuelle per-kilde-knapper. Auto-opdaterer status efter 20 sekunder.

### ~~ENHANCEMENT — Årsrapportvisning~~ ✅ Rettet
~~Ingen årsaggregering eksisterer.~~ Nyt topniveau-faneblad med `getAnnualSummary.php` + `includes/annual.js`. Grupperet søjlediagram og tabel viser EV kWh, EV pris, VP kWh og Hus total kWh pr. kalenderår.

### ENHANCEMENT — CO2-emissionssporing
Energi Data Service eksponerer `co2emissionsprognose` (CO2-prognose pr. kWh pr. time) for DK1/DK2. Den samme pipeline som bruges til elpriser kunne vedhæfte gCO2/kWh til hver ladningssession.
**Filer:** `getElspotPrices.php` eller ny `getCo2Data.php`, `getDashboardSummary.php`

### ENHANCEMENT — Budget / månedligt forbrugsmål
Tillad brugere at sætte et månedligt EV-opladningsbudget (kr) og et målsat varmepumpe månedligt kWh i settings-modalen. Vis en fremdriftsbjælke på dashboard-kortene mod disse mål. Ingen backend-ændringer nødvendige — mål gemt i localStorage.
**Filer:** `index.php` (settings modal), `includes/dashboard.js`

### ENHANCEMENT — API-responscaching for dashboard-endpunkt
`getDashboardSummary.php` kører 10+ SQLite-queries ved hvert sideload. En 60-sekunders filbaseret cache (samme mønster som `getElspotPrices.php`) ville gøre dashboardet næsten øjeblikkeligt ved gentagne besøg.
**Filer:** `getDashboardSummary.php`

---

## Priority: Low

### ENHANCEMENT — Sparkline x-akse viser de seneste 30 dage i stedet for måned-til-dato
Den 1. i måneden viser begge sparklines en enkelt søjle. En rullende 30-dages vindue (altid 30 søjler) ville opretholde visuel konsistens i hele måneden.
**Filer:** `getDashboardSummary.php`, `includes/dashboard.js`

### ENHANCEMENT — URL-hash inkluderer datointervalfilter
Datointervalfilteret er aldrig kodet i URL-hashen. At vælge "Dette år" og bogmærke giver en URL der åbner med standardfilteret "Denne måned".
**Filer:** `includes/nav.js`, `includes/elbil.js`

### ENHANCEMENT — Tastaturgenveje til fanebladnavigation
Ingen tastaturgenveje eksisterer. `O`, `E`, `J`, `H` for Oversigt/Elbil/Jordvarme/Hus, `Escape` til at lukke modaler.
**Filer:** `includes/nav.js`, `includes/app.js`

### ENHANCEMENT — Komplet mørkt tema
CSS'en har kun en enkelt `@media (prefers-color-scheme: dark)` tilsidesætning for `.dashboard-card`. Diagrammer, tabeller, modaler og skæbelågen bevarer lyse baggrunde i mørk tilstand.
**Filer:** `includes/style.css`

### ENHANCEMENT — Progressive Web App (PWA)
Appen har en `favicon.svg` og `robots.txt` men intet `manifest.json` eller service worker. Tilføjelse af et web app-manifest og grundlæggende service worker caching af statiske aktiver ville tillade installation på startskærmen.
**Filer:** ny `manifest.json`, ny `sw.js`, `index.php`

### ENHANCEMENT — Varmepumpe COP-estimering
Med udendørstemperaturdata (allerede hentet fra Open-Meteo til GD) og forbrugt kWh kan et groft COP-estimat udledes. At vise "Estimeret leveret varme: X kWh (COP ≈ Y)" i Jordvarme-statistikboksen ville give indsigt i varmesystemets ydeevne.
**Filer:** `includes/jordvarme.js`, `getWeatherData.php`

### ENHANCEMENT — Opladningstabel kolonne for sessionstilstand
Monta API leverer `state` og `stopReason` (f.eks. `completed`, `stopped_by_user`, `error`). Gemt i `charges`-tabellen men aldrig vist.
**Filer:** `getCharges.php`, `includes/elbil.js`

### ENHANCEMENT — Fejllog-visning i settings-modal
`cron/cron.log` er SSH-eksklusiv. En sammenklappelig "Systemlog"-sektion i settings-modalen der viser de seneste 50 linjer ville hjælpe brugeren med at diagnosticere sync-fejl uden at forlade browseren.
**Filer:** ny `getSystemLog.php`, `index.php`

### ~~ENHANCEMENT — Monta køretøjsnavn auto-opdatering ved sync~~ ✅ Rettet
~~`cron/update_monta_data.php` opdaterer aldrig `vehicleName` i `vehicles`-tabellen fra Monta API.~~ `upsertVehicle()` kaldes nu efter hvert charge-match. `receive_vehicle_data.php` accepterer valgfrit `vehicleName`.

### ENHANCEMENT — Opladningskort / stedssporing
Tilføjelse af et simpelt kort over besøgte opladningssteder (Leaflet.js) ville give en "hvor har jeg ladet"-dimension.
**Filer:** `cron/update_monta_data.php`, `getCharges.php`, `includes/elbil.js`

### ENHANCEMENT — State-of-charge (SoC) sporing
`charges`-tabellen gemmer `socPercentage` og `socLimit` fra Monta, men UI'en viser dem aldrig.
**Filer:** `getCharges.php`, `includes/elbil.js`

---

## Architecture / Technical Debt

### DEBT — README.md er tom
**Filer:** `README.md`

### DEBT — Ingen CHANGELOG
**Filer:** ny `CHANGELOG.md`

### ~~DEBT — `getChargeAnalytics.php` og `getDashboardSummary.php` har ingen output-caching~~ ✅ Rettet
~~Begge endpoints rammer SQLite ved hvert request.~~ `getDashboardSummary.php` caches 60 sekunder (per-dag filnavn). `getChargeAnalytics.php` caches 300 sekunder (md5-nøgle på alle GET-parametre). Samme `/tmp/sparkspend_*.json`-mønster som `getElspotPrices.php`.

### DEBT — Ingen inputvalidering på `receive_vehicle_data.php`
Dette endpoint accepterer `vehicleId` og `odometer` fra POST JSON uden validering af at `vehicleId` eksisterer eller at `odometer` er et positivt tal.
**Filer:** `receive_vehicle_data.php`

### DEBT — `check_data_integrity.php` dækker kun varmepumpedatabasen
EV `charging_data.db` har ingen tilsvarende integritetstjek.
**Filer:** `check_data_integrity.php`

### DEBT — CDN-afhængigheder indlæst uden versionspinning på Chart.js
En brudende Chart.js v5-udgivelse ville lydløst bryde alle diagrammer.
**Filer:** `index.php`

### DEBT — `cron/update_monta_data.php` har ingen rate-limit-håndtering
Ingen retry-logik eller 429-håndtering. En rate-grænse kunne lydløst afkorte importen.
**Filer:** `cron/update_monta_data.php`

### DEBT — Elpriscache bruger `/tmp` som er flygtig på nogle hosts
`getElspotPrices.php` skriver JSON-caches til `/tmp/sparkspend_*.json`. En env-konfigurerbar `CACHE_DIR` (standard `data/cache/`) ville være mere pålidelig.
**Filer:** `getElspotPrices.php`, `includes/configuration.php`

---

## New Feature Ideas

### FEATURE — Solcelle / PV-produktionsoverlay
Integrer en PV-produktions-API for at overlappe solproduktion mod EV-opladningsbehov og varmepumpeforbrug. Vis "selvforbrugsprocent": hvor meget af hjemmeladningen blev dækket af solenergi.

### FEATURE — Time-of-use elpriskort
Render en ugentlig heatmap (time × ugedag) der viser gennemsnitlig elspot-pris pr. celle. Overlej faktiske ladningssessioner som prikker.

### FEATURE — Elprisforecast-widget
Brug den 24-timers Energi Data Service spot-prisforecast til at vise morgendagens priskurve. Fremhæv det billigste opladningsvindue for de næste 24 timer.

### FEATURE — Månedlig rapport email/PDF
Generer et udskrivbart månedsoversigt (kWh, pris, tendenser, sparklines) og send det via email.

### FEATURE — Anomali-detektionsadvarsler
Baggrundstjek daglige aflæsninger mod en rullende baseline for både EV og varmepumpe. Vis et badge på det relevante faneblad og en udvidelig advarselliste i headeren.

### FEATURE — Integration med Tibber / Nordpool live-pris
Tibber eksponerer en realtids el-pris websocket. Tilslutning til den ville muliggøre live kr/kWh-visning på Oversigt-kortet.

---

## Næste Sprint — Anbefaling

Fokus: **Datakvalitet + UX-forbedringer** — Sprint afsluttet ✅

| # | Item | Prioritet | Status |
|---|---|---|---|
| 1 | ~~**BUG** `ext_charges.datetime` faux-UTC fix~~ | High | ✅ Afsluttet |
| 2 | ~~**BUG** `provideres` → `providers` rename~~ | High | ✅ Afsluttet |
| 3 | ~~**ENHANCEMENT** Jordvarme estimeret kr-pris (alle 4 tilstande)~~ | High | ✅ Afsluttet |
| 4 | ~~**ENHANCEMENT** Dashboard lazy-init (chart.resize ved tab-skift)~~ | Medium | ✅ Afsluttet |
| 5 | ~~**ENHANCEMENT** Dashboard genlæser ved settings-ændring~~ | High | ✅ Afsluttet (N/A + localStorage-bug fix) |

---

## Sprint 3 — Anbefaling

Fokus: **Selvbetjening + EV-detaljer**

| # | Item | Prioritet | Status |
|---|---|---|---|
| 1 | ~~**ENHANCEMENT** Vehicle management UI (omdøb)~~ | High | ✅ Afsluttet |
| 2 | ~~**ENHANCEMENT** Provider management UI (opret/omdøb/slet)~~ | High | ✅ Afsluttet |
| 3 | ~~**DEBT** Monta vehicleName auto-opdatering i sync-cron~~ | Low | ✅ Afsluttet |
| 4 | ~~**ENHANCEMENT** EV opladningssession effekt (kW) + varighed~~ | Medium | ✅ Afsluttet |
| 5 | ~~**DEBT** Output-caching på `getDashboardSummary.php` + `getChargeAnalytics.php`~~ | Medium | ✅ Afsluttet |

---

## Sprint 4 — Anbefaling

Fokus: **Indsigt + Driftstransparens**

| # | Item | Prioritet | Status |
|---|---|---|---|
| 1 | **ENHANCEMENT** EV hjemmeladning kr fra spotpris | Medium | ~~Fravalgt — Montas cost-felt er validt~~ |
| 2 | ~~**ENHANCEMENT** Årsrapportvisning~~ | Medium | ✅ Afsluttet |
| 3 | ~~**ENHANCEMENT** Global sync-statusindikator~~ | Medium | ✅ Afsluttet |
| 4 | ~~**ENHANCEMENT** "Sync nu"-knap~~ | Medium | ✅ Afsluttet |
| 5 | **ENHANCEMENT** Sparkline rullende 30-dages vindue | Low | Åben |
