# SparkSpend — Product Backlog

Generated: 2026-03-11
Covers all features across Oversigt, Elbil, Jordvarme, architecture, and UX.
Items within each priority tier are ordered by impact.

---

## Priority: High

### BUG — `provideres` table/filename typo is frozen in schema
The SQLite table is named `provideres`, the endpoint is `getProvideres.php`, and `QueryBuilder::selectAllProviders()` queries it. A one-time migration (`ALTER TABLE provideres RENAME TO providers`) plus file/reference rename would fix permanently. Currently perpetuates across all callers.
**Files:** `includes/QueryBuilder.php`, `getProvideres.php`, all callers

### BUG — `ext_charges.datetime` stored as faux-UTC (local time + Z suffix)
Both `createExCharge.php` and `updateExtCharge.php` use `new DateTime($input)->format('Y-m-d\TH:i:00\Z')`. This appends a literal `Z` without converting to UTC. When PHP later calls `strtotime('2026-03-11T23:30:00Z')` it interprets as UTC (00:30 the next day in CET), shifting charges near midnight by 1–2 hours. Fix: use `setTimezone(new DateTimeZone('UTC'))` before formatting, or store without Z in `YYYY-MM-DD HH:MM:SS` format consistently across both endpoints.
**Files:** `createExCharge.php`, `updateExtCharge.php`, all PHP-side date filtering callers

### ENHANCEMENT — Heat pump estimated cost (kr) missing from Jordvarme tab
The sophisticated electricity price calculation (`getElspotPrices.php`) exists and correctly computes spot + nettarif + systemtarif + elafgift + moms for a given date range, but it is never applied to heat pump consumption data. Users see kWh only, not kr. Adding an "Estimated cost" stat to all four jordvarme modes (daily, monthly, compare, YTD) using `getElspotPrices.php` would close the most obvious information gap in the app.
**Files:** `getHeatpumpData.php`, `includes/jordvarme.js`, `getElspotPrices.php`

### ENHANCEMENT — Vehicle management UI
Vehicles exist in SQLite but can only be created/renamed by direct database manipulation. The Monta sync auto-creates with vehicleId from the API but the `vehicleName` is never updated from the API response (if the name changes). A simple settings-adjacent modal to rename vehicles would remove the need for SSH access.
**Files:** `index.php`, new `getVehicles.php` (already exists), new `updateVehicle.php`

### ENHANCEMENT — Provider management UI
Same problem as vehicles: providers (charging operators) can only be added via direct DB access. No endpoint exists to create/rename/delete providers. A card in the Elbil → Sammenligning sub-tab, or a settings panel, with a simple provider list + add/rename/delete would make the app self-sufficient.
**Files:** `index.php`, new `createProvider.php`, new `updateProvider.php`, new `deleteProvider.php`

### ENHANCEMENT — Dashboard reloads on settings change
When the user updates lat/lon in the settings modal, `_enrichHpWithWeather` in `dashboard.js` continues using the old cached localStorage values until the page is reloaded. The settings save handler in `jordvarme.js` should dispatch a `settings:saved` event on `window.SparkEvents`, and `dashboard.js` should subscribe to trigger a fresh `loadDashboard()`.
**Files:** `includes/jordvarme.js`, `includes/dashboard.js`

---

## Priority: Medium

### ENHANCEMENT — EV home charging cost from spot price
Currently, EV home charging costs are taken directly from the Monta API `cost` field. Monta's cost may reflect a flat tariff or inaccurate rate. The existing `getElspotPrices.php` infrastructure could instead calculate the true electricity cost for home charging sessions (spot + tariffs), giving the user accurate and transparent pricing — the same methodology used for the heat pump.
**Files:** `getCharges.php` or new `getHomeChargeCost.php`, `includes/elbil.js`

### ENHANCEMENT — EV multi-year compare mode (mirrors Jordvarme)
The Jordvarme tab has a `Sammenligning` mode that overlays multiple years' monthly consumption on a single chart. The EV tab has vehicle comparison but no equivalent year-over-year overlay showing charging patterns across calendar years. A mode showing monthly kWh or cost per year as overlaid line/bar series would give the same historical context.
**Files:** `includes/elbil.js`, new `getChargesByYear.php` or extend `getChargeAnalytics.php`

### ENHANCEMENT — Charging session power (kW) and duration
The Monta API sends `cablePluggedInAt`, `startedAt`, and `stoppedAt`. Session duration (`stoppedAt - startedAt`) and average power (`consumedKwh / duration_hours`) are never calculated or displayed. Adding these as columns or tooltip data in the charge table would help users identify unusually slow sessions and understand charger performance.
**Files:** `includes/elbil.js`, `getCharges.php` (add computed columns)

### ENHANCEMENT — CSV / Excel export for charge history
No export functionality exists anywhere in the app. Users cannot extract their own data without SQLite access. A simple "Export CSV" button on the charge table (sending current filters to a `exportCharges.php` endpoint) and a monthly summary export on the jordvarme tab would meet basic data portability expectations.
**Files:** new `exportCharges.php`, new `exportHeatpump.php`, `includes/elbil.js`, `includes/jordvarme.js`, `index.php`

### ENHANCEMENT — Sync status indicator in UI
`cron/cron.log` shows last sync time and any errors but is only readable via SSH. A small sync status indicator (last sync timestamp + OK/error badge) visible in the settings modal or header would help the user diagnose stale data without leaving the browser. A lightweight `getSyncStatus.php` reading from the `sync_log` SQLite table would suffice.
**Files:** new `getSyncStatus.php`, `index.php`, `includes/nav.js` or settings modal

### ENHANCEMENT — "Sync now" button for Monta and heat pump
Currently both data sources update only via scheduled cron. A settings modal "Sync nu" button calling `cron/update_monta_data.php` and `cron/sync_heatpump_data.php` via a thin PHP wrapper (with a 30-second timeout guard and CSRF protection) would let users pull fresh data on demand without scheduling knowledge.
**Files:** new `triggerSync.php`, `index.php` (settings modal)

### ENHANCEMENT — Annual summary view
No year-level aggregation exists. A "Årsrapport" view showing each calendar year as a row — total EV kWh, total EV cost, total HP kWh, estimated HP cost, comparing year over year — would give the clearest high-level picture of energy spending trends and suit an annual review workflow.
**Files:** new `getAnnualSummary.php`, `includes/dashboard.js` or new `includes/annual.js`, `index.php`

### ENHANCEMENT — CO2 emissions tracking per charge
Energi Data Service exposes `co2emissionsprognose` (CO2 forecast per kWh by hour) for DK1/DK2. The same pipeline used for electricity prices could attach gCO2/kWh to each charge session, giving a monthly CO2 footprint alongside cost and kWh. This is particularly relevant for comparing home (grid-mix) vs. public fast charging.
**Files:** `getElspotPrices.php` or new `getCo2Data.php`, `getDashboardSummary.php`, `includes/dashboard.js`

### ENHANCEMENT — Smart charging cost comparison ("What if I charged at midnight?")
For each home charging session, calculate what it would have cost if charged at the cheapest available 2-hour window that day. Show the delta ("You spent 14 kr more than the cheapest available window"). This surfaces the value of time-of-use optimisation and is computed entirely from existing spot price data.
**Files:** `getCharges.php` or new `getChargeCostComparison.php`, `includes/elbil.js`

### ENHANCEMENT — Budget / monthly spending target
Allow users to set a monthly EV charging budget (kr) and a target heat pump monthly kWh in the settings modal. Show a progress bar on the dashboard cards against these targets, coloured green/amber/red based on pace (current projected cost vs. target). No backend changes needed — targets stored in localStorage.
**Files:** `index.php` (settings modal), `includes/dashboard.js`, `includes/style.css`

### ENHANCEMENT — Dashboard lazy-init when Oversigt tab is not active on load
`loadDashboard()` fires on `DOMContentLoaded` unconditionally. If SparkNav restores a different tab (e.g. Elbil from the last URL hash), `#oversigt-section` is `display:none` and Chart.js draws sparklines at 0×0 pixels. SparkNav already lazy-inits Elbil and Jordvarme; the same pattern should be applied to Dashboard — call `loadDashboard()` on first show of the Oversigt tab rather than immediately on DOM ready. Also call `chart.resize()` when the section is revealed.
**Files:** `includes/nav.js`, `includes/dashboard.js`

### ENHANCEMENT — API response caching for dashboard endpoint
`getDashboardSummary.php` runs 8+ SQLite queries on every page load and every `charge:saved` event. Adding a 60-second file-based cache (same pattern as `getElspotPrices.php`) would make the dashboard feel instant on repeat visits, at the cost of up to 60 seconds of stale data — acceptable for a personal dashboard.
**Files:** `getDashboardSummary.php`

---

## Priority: Low

### ENHANCEMENT — Sparkline x-axis shows last 30 days instead of month-to-date
On day 1 of the month both sparklines show a single bar. On day 2, two bars with no scale. A rolling 30-day window (always 30 bars) would maintain visual consistency throughout the month and give more context at month start. The sparkline data would change from "from day 1 of current month" to "from 30 days ago".
**Files:** `getDashboardSummary.php`, `includes/dashboard.js`

### ENHANCEMENT — URL hash includes date range filter
The filter date range is never encoded in the URL hash. Selecting "This year" and bookmarking gives a URL that opens on the default "This month" filter. Encoding the active quick filter or the explicit date range in the hash (e.g. `#elbil/ladninger?range=year`) would make filtered views bookmarkable and shareable.
**Files:** `includes/nav.js`, `includes/elbil.js`

### ENHANCEMENT — Keyboard shortcuts for tab navigation
No keyboard shortcuts exist. `O`, `E`, `J` for Oversigt/Elbil/Jordvarme, `Escape` to close modals and the filter drawer, and `?` to show a shortcut reference overlay would improve keyboard-first usability.
**Files:** `includes/nav.js`, `includes/app.js`

### ENHANCEMENT — Comprehensive dark mode theme
The CSS has a single `@media (prefers-color-scheme: dark)` override for `.dashboard-card` only. Charts, tables, modals, the filter drawer, and form elements retain light backgrounds in dark mode. A full `[data-theme="dark"]` CSS variable override set (or completing the existing media query) would make the app comfortable for night-time use.
**Files:** `includes/style.css`

### ENHANCEMENT — Progressive Web App (PWA)
The app has a `favicon.svg` and `robots.txt` but no `manifest.json` or service worker. Adding a web app manifest (name, icons, display: standalone, theme_color) would allow users to install SparkSpend to their home screen on mobile/desktop. A basic service worker caching the static assets would also make the UI load offline.
**Files:** new `manifest.json`, new `sw.js`, `index.php` (add manifest + SW registration)

### ENHANCEMENT — Heat pump COP estimation
With outdoor temperature data (already fetched from Open-Meteo for HDD) and consumed kWh (from the powerlog), a rough COP estimate can be inferred using a standard ground-source heat pump COP model. Displaying "Estimated delivered heat: X kWh (COP ≈ Y)" in the jordvarme stats box would give the user insight into heating system performance.
**Files:** `getHeatpumpData.php` or `includes/jordvarme.js`, `getWeatherData.php`

### ENHANCEMENT — Charge table column for session state / stop reason
The Monta API provides `state` and `stopReason` fields (e.g. `completed`, `stopped_by_user`, `error`). These are stored in the `charges` table but never surfaced in the UI. A small status badge in the charge table would help identify failed or manually stopped sessions that may have under-reported kWh.
**Files:** `getCharges.php`, `includes/elbil.js`

### ENHANCEMENT — Error log viewer in settings modal
`cron/cron.log` and PHP error log are SSH-only. A collapsible "Systemlog" section at the bottom of the settings modal reading the last 50 lines of `cron/cron.log` via a `getSystemLog.php` endpoint would let users diagnose sync failures without leaving the browser.
**Files:** new `getSystemLog.php`, `index.php` (settings modal)

### ENHANCEMENT — Monta vehicle name auto-update on sync
`cron/update_monta_data.php` detects vehicleId by matching plug-in timestamps against the `vehicle_charges` odometer table, but never updates the `vehicleName` in the `vehicles` table from the Monta API. If Monta's vehicle display name changes, the local name goes stale. The sync should check and update vehicle names from the API response.
**Files:** `cron/update_monta_data.php`

### ENHANCEMENT — Charge map / location tracking
Monta likely returns charge point location (GPS coordinates or address) in its API response. Storing and displaying a simple map of charging locations visited (using Leaflet.js from CDN) would add a "where have I charged" dimension that pure cost/kWh charts cannot show.
**Files:** `cron/update_monta_data.php`, `getCharges.php`, `includes/elbil.js`, `index.php`

### ENHANCEMENT — State-of-charge (SoC) tracking
The `charges` table stores `socPercentage` and `socLimit` from Monta but the UI never displays them. Showing start/end SoC% in the charge table or as a chart series over time would help users understand battery degradation and charging patterns.
**Files:** `getCharges.php`, `includes/elbil.js`

---

## Architecture / Technical Debt

### DEBT — README.md is empty
`README.md` exists but contains no content. At minimum it should describe what SparkSpend is, list prerequisites (PHP, SQLite, MySQL remote, Monta API account), document the `.env` keys, and explain how to run the cron jobs.
**Files:** `README.md`

### DEBT — No CHANGELOG
No version history or changelog exists. Git commits have good messages but there is no human-readable changelog summarising releases.
**Files:** new `CHANGELOG.md`

### DEBT — `getChargeAnalytics.php` and `getDashboardSummary.php` have no output caching
Both endpoints hit SQLite on every request. `getElspotPrices.php` uses file-based caching with a `CACHE_VERSION` constant. The same pattern should be applied to analytics and dashboard endpoints to reduce I/O on busy days.
**Files:** `getChargeAnalytics.php`, `getDashboardSummary.php`

### DEBT — No input sanitisation on `receive_vehicle_data.php`
This endpoint accepts `vehicleId` and `odometer` from POST JSON without validating that `vehicleId` exists in the `vehicles` table or that `odometer` is a positive number. A rogue POST could insert garbage odometer readings that silently corrupt the km/kWh efficiency chart. Add existence check + range validation.
**Files:** `receive_vehicle_data.php`

### DEBT — `check_data_integrity.php` only covers heatpump database
The EV `charging_data.db` has no equivalent integrity check. Adding checks for NULL `vehicleId`, negative `consumedKwh`, future `createdAt` dates, and orphaned `ext_charges` (providerId not in `provideres`) would catch sync or manual entry errors early.
**Files:** `check_data_integrity.php`

### DEBT — CDN deps loaded with no version pinning on Chart.js and ApexCharts
`index.php` loads `chart.js` without a version pin (resolves to latest). A breaking Chart.js v5 release would silently break all charts. Pin all CDN dependencies to explicit versions (as Bootstrap 5.1.3 already is).
**Files:** `index.php`

### DEBT — `cron/update_monta_data.php` has no rate-limit handling
The Monta API pagination loop has no retry logic or 429 (Too Many Requests) response handling. On a large initial sync a rate limit could silently truncate the import. Add `Retry-After` header handling and exponential backoff.
**Files:** `cron/update_monta_data.php`

### DEBT — Electricity price cache uses `/tmp` which is ephemeral on some hosts
`getElspotPrices.php` writes JSON caches to `/tmp/sparkspend_*.json`. On some hosting environments `/tmp` is cleared on restart, invalidating the cache unexpectedly. An env-configurable `CACHE_DIR` (defaulting to `data/cache/`) would be more reliable.
**Files:** `getElspotPrices.php`, `includes/configuration.php` (add `CACHE_DIR` key)

---

## New Feature Ideas

### FEATURE — Solar / PV production overlay
If the user has a solar installation, integrate a PV production API (e.g. Solaredge, Enphase, or a local inverter) to overlay solar yield against EV charging demand and heat pump consumption. Show "self-consumption %": how much of home charging was covered by solar generation. This is the logical next sensor for a home energy dashboard.

### FEATURE — Time-of-use electricity tariff calendar
Render a weekly heatmap (hour × day-of-week) showing average electricity spot price per cell. Overlay actual charge sessions as dots. This instantly visualises whether the user tends to charge at cheap or expensive hours and identifies optimisation opportunities.

### FEATURE — Electricity price forecast widget
Use the 24-hour Energi Data Service spot price forecast to show tomorrow's price curve on the Oversigt or Jordvarme card. Highlight the cheapest charging window for the next 24 hours with a suggested "Charge from HH:MM to HH:MM" recommendation.

### FEATURE — Monthly report email / PDF
Generate a printable monthly summary (current month kWh, cost, trends, sparklines) using PHP's built-in HTML→PDF path or a template rendered to a print-stylesheet. A "Send rapport" button that emails the report to a configured address (via SMTP in `.env`) would suit a recurring review workflow.

### FEATURE — Multi-user / household support
Currently the app is single-user with no authentication. Adding a simple session-based login (username + bcrypt password stored in a `users` SQLite table) and associating vehicles/settings per user would allow multiple household members to have their own views. Low-complexity auth is sufficient for a private home deployment.

### FEATURE — Anomaly detection alerts
Background-check daily readings against a rolling baseline for both EV (unusually high single charge cost) and heat pump (unusually high daily kWh vs. HDD-adjusted expectation). Store alerts in a SQLite `alerts` table. Show a badge on the relevant tab and an expandable alert list in the header when anomalies are detected.

### FEATURE — Integration with Tibber / Nordpool live price
Tibber exposes a real-time electricity price websocket. Connecting to it would allow live cost-per-kWh display on the Oversigt card (updated every minute) and enable the Smart Charging cost comparison feature with live data rather than yesterday's actuals.
