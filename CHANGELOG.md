# Changelog

All notable changes to SparkSpend are listed here in reverse chronological order.

---

## [Unreleased]

### Quality
- **`DashboardSummary::build()` (450 lines, thirty date variables) split** into `DashboardPeriods` (the calendar of a request, built from one instant), `DashboardEv` (the EV card) and `DashboardMeters` (heat pump, house, rest); `build()` only orchestrates, and takes an optional `$now`. Proved unchanged with a golden test: `dev/golden/capture.sh` runs the dashboard under a frozen clock (libfaketime, in Docker) for production data and seed data at seven instants (ordinary day, the 1st just after midnight, month end after a short February, the 23- and 25-hour DST days, new year, a leap day) - 14 outputs, byte-identical before and after; mutation-checked. `dev/test_dashboard_periods.php` (20 checks) covers the calendar. `lyScale` (a capped last-year window) only differs from 1 on 29 February, where there is no data from the year before, so the golden files cannot see it; the code was moved verbatim.
- **CI was red from the cron-runner commit on (7 pushes) and I did not notice.** `test_cron_job.php` cut a 800-character multibyte error message to 500 *bytes* in its expectation, but with `mbstring` (CI, production) `mb_substr` cuts at 500 *characters*; my local PHP had no `mbstring`, so it passed there. The test now checks characters and valid UTF-8 either way. `dev/ci-local.sh` runs the CI steps (syntax, tests, PHPStan) in a Linux PHP 8.3 container with the CI extensions, so this cannot pass locally and fail remotely again.
- **PHPStan (level 4) in CI** with a baseline of the 20 findings that existed (`phpstan.neon`, `phpstan-baseline.neon`; the same Docker image locally and in CI). New problems fail the build; a baseline entry that no longer matches must be removed. On the first run it found nothing real: `$husDailyMap` "might not be defined" is a false positive (its only read sits behind `$husCurrentKwh !== null`), but it is now initialised and pinned by `dev/test_dashboard_resilience.php`; `end()` on a possibly empty array in `ChargerSessions` became `lastEnergy()`.
- **Data integrity check** (`DataIntegrity`, run by `php doctor.php`): any INTEGER/REAL column holding a number-looking text or blob (what the BLOB `vehicleId` was), and rows that point at a car or provider that does not exist. `dev/test_data_integrity.php` (23 checks) writes through every writer in the app with string input, as it arrives from HTTP/JSON, and asserts nothing ends up as text or blob. Production data is clean.
- `docs/TIME.md`: which column is UTC and which is local wall-clock, and the rules that follow (DST, string comparison across formats).
- Cleanup: `reset_heatpump_database.sh` (it deletes the heat pump database) moved from the web root to `cron/archive/`; `dev/debug_dashboard.php` and `.py` (stale re-implementations of the dashboard) removed. The copy already on the server stays until deleted by hand (the deploy skips `*.sh`).

### Security
- **Found by probing production on the internal network, bypassing the login proxy:** `.env`, `.git/config`, both SQLite databases, `cron/cron.log` and the source were served without any login, the JSON API answered, and `.php` files in `includes/`, `routes/`, `cron/` and `migrate.php` ran when fetched (fetching `cron/update_monta_data.php` ran a Monta sync). Only status codes and sizes were read. The repository is the web root and nothing in it said what the web server may serve.
- Fixes in the app: `docs/nginx-sparkspend.conf` (serve only the entry points and the assets); `TRUSTED_PROXIES` (`PeerGuard`: the pages and the API answer 403 to every peer but the proxy; Bearer-key endpoints unaffected); route files refuse to run outside the bootstrap; every script in `cron/` (also `cron/archive/`: one-off data fixes) and `migrate.php` is CLI-only; `php doctor.php --probe=URL` fetches the secret paths over HTTP and fails if any is served (never a script that would act when fetched). `dev/test_security.php` (40 checks). The nginx block was tested against real nginx + php-fpm 8.4 in containers: all 16 secret paths refused, the pages, API, assets, `sw.js.php`, `manifest.json` and the machine endpoints work, every other `.php` is 404, and `TRUSTED_PROXIES` refuses the pages/API (403) but not the Bearer endpoints. The nginx block and `TRUSTED_PROXIES` must be set on the server; nothing is closed on production until they are.

### Changed
- **Cache invalidation by declared dependencies.** `CacheHelper::DEPENDENCIES` says which kinds of data (`charges`, `providers`, `vehicles`, `meter`, `ha`, `prices`, `sync`) each cached view is built from; a writer calls `CacheHelper::invalidate(['charges'])` with what it changed, and every dependent view goes. The hand-kept lists per writer were wrong in five places, now fixed: a charge change did not refresh house power (up to 1 h), the base load or the charge optimiser; odometer readings did not refresh efficiency; charger events refreshed nothing; a vehicle rename was not picked up by the comparisons; new prices did not refresh the 1-hour views. Fail-safe: `ApiCache` refuses to cache a key whose prefix is not registered. `dev/test_cache_deps.php` (59 checks, mutation-checked) fails if a route caches under an unregistered prefix, a writer does not invalidate its tags, or a new route writes without being on the writers list.
- Cache files are cleaned up: the date/hour in the keys left new files every day (the optimiser's key has the hour in it). `cron/warm_prices.php` now removes JSON views older than 2 days and raw price files older than 45 days (a finished year's prices are valid for 30 days plus a 7-day offline fallback), and drops the price-dependent views when it fetched something new. Every cron run refreshes the sync status view.
- **Cron jobs rebuilt on one runner, and tested.** `CronJob` (one run at a time via `flock`, one log format, exit codes 0/1/2, catches `Error` as well as `Exception`), `SyncLog` (watermark / run time / error), `MeterSync` (the heat pump and house copies of 300 lines each became one class with the MySQL side behind a fetch function), `MontaSync` + `ChargeImporter` + `MontaClient` (the 400-line Monta script: HTTP, import and orchestration separated). The scripts are 13-24 lines each. 84 new checks (`test_cron_job`, `test_meter_sync`, `test_monta_sync`), mutation-checked; the Monta import - which was untested - is now covered: UTC normalisation, pairing, manual corrections surviving a re-sync, all-or-nothing batches.
- Fixed on the way: a failed heat pump run wiped its watermark (next run started over from the first record); a failure in Monta's token / HTTP steps left no trace in `sync_log` (only the log); a charge without id would have been inserted again on every run; the heat pump job did not record a run that found nothing new (it looked dead when idle); a lock older than 60 s was treated as dead even if the run was alive; an `Error` (not `Exception`) slipped past the heat pump / house catch. Quiet logs: a run that finds nothing writes nothing.
- `trigger_sync` no longer wraps the scripts in its own `flock`; the jobs lock themselves. Cron scripts refuse to run from the web.
- `/health.php` and the Oversigt warning also watch the heat pump meter's newest reading.

### Added
- **Data freshness and monitoring.** `includes/Freshness.php` judges how old the newest data from each source is (Monta, heat pump, house, the meter's newest reading, electricity prices; device energy only when Home Assistant sends it). `health.php` (Bearer `HEALTH_KEY`) answers 200 or 503 for Uptime Kuma; Oversigt shows a warning when something is stale; the per-card "Data: ..." label turns red from the same verdict. `php doctor.php` is a read-only preflight for a deployment (env, folders, pending migrations, cron paths, freshness). Tests: `dev/test_freshness.php`, `dev/test_doctor.php`, health checks in `dev/test_routes.php`.
- Device energy from Home Assistant also accepts `fryser`, `tv_stue` and `kontor_it` (daily utility_meter + same automation, see docs/home-assistant/device_energy.yaml). Stored only; not shown in the views yet.
- **Basislast** (Hus > Forbrug): what the house draws when nothing big runs, in watts per month for two years (the mean of each day's three lowest hours without EV charging and heat pump, median per month), with the same month a year ago. `includes/BaseLoad.php`, `api.php?action=base-load`, `dev/test_base_load.php`. On the production data it is 190-310 W (about half of Restforbrug); 2026 is 10-25 % below 2025.
- **Varme - Skur inside Restforbrug.** Home Assistant sends the shed heater's daily kWh (`receive_device_energy.php`, `device_energy` table via migration `powerlog/005`, idempotent, same Bearer key as the other HA endpoints); Hus > Forbrug shows "heraf Varme - Skur" and Hus > Regning a column. The shed heater is 27-28 % of Restforbrug in Jan-Feb (about 330 kWh per winter). Other devices can be sent the same way. Install steps, the HA automation and the winter 2025/26 backfill are in `docs/home-assistant/`.
- **Automatic log rotation.** `cron/cron.log` (56 MB in production) is now rotated by size by the sync jobs themselves (`includes/CronLog.php`, `LOG_MAX_BYTES` default 5 MB, `LOG_KEEP` default 3; safe when the jobs run at the same time) and the system-log view also reads the rotated files. `cron/logrotate.sparkspend` covers the logs that cron writes with a shell redirect (`prices.log`, the PHP error log). Covered by `dev/test_cron_log.php`.

### Fixed (found by running the code against a copy of the production data)
- **Two charges (57.8 kWh, Feb 2025) belonged to no vehicle:** their `vehicleId` was stored as a BLOB, which SQLite does not match with `vehicleId = 2`, so they were missing from vehicle filters, per-vehicle totals and efficiency. Migration `006_fix_vehicleid_storage_type.php` stores them as integers (`php migrate.php`); covered by `dev/test_vehicleid_migration.php`.
- **Årsrapport partial-year label** is now based on where the house / heat pump meters start (the meters' own gap Nov 2021 - Oct 2022 does not make 2022 partial, because yearly totals come from counter differences), not on how many months have EV charges (that wrongly marked 2024). New `data_months` field in the annual API.

### Changed (UX review)
- **Elspot time pattern (Elbil > Analyse): the week reads Man (top) to Søn (bottom).** ApexCharts draws the first series at the bottom, so it ran Søn to Man.
- **The overview's price card no longer disappears silently** when the price request fails: it stays and says why (HTTP status).
- **Phones:** Hus > Regning is one card per month (label left, value right) instead of an eight-column table; the other wide tables (Analyse) get a shadow at the edge that still scrolls; charts are no longer 70 px high (Chart.js default aspect ratio 1.2 on screens under 600 px); the statistics lists show the label above the value.
- **Phone and layout fixes found by checking every view:** the summary table on Ladninger was rendered as a stacked card list with the headings detached from the values (the card layout meant for the charge table also hit it); the anomaly rows were squeezed into a column a few letters wide with the button overflowing; the Elbil sub-navigation cut off its last pill (Administrér is now an icon on phones); the year chips and period selector stretched full width; Årsrapport's chart was tiny. Desktop: the statistics column on Jordvarme/Hus is wider (5/12) so the year-comparison table no longer wraps, and the banner explaining a tab dot is as wide as the card.
- **Navigation and layout.** Every tab now has the same fixed width (1200 px max); before, each section was as wide as its content, so the sub-navigation and cards jumped sideways when switching tabs (Oversigt was 740 px, Analyse 1200 px). The period selector (Daglig / Månedlig / Sammenligning / År til dato) is now a segmented control inside the card, visibly a different level from the topic pills (Forbrug / Regning, Ladninger / Analyse). Årsrapport's chart uses the full width.
- **Fixed: the statistics box wrapped under the chart** on Jordvarme and Hus. A custom `.row { gap: 20px }` in `style.css` overrode the Bootstrap grid so `col-md-8` + `col-md-4` added up to more than 100 %; the override is removed and the Bootstrap grid is used as is.
- **Partial months and years are no longer shown as full ones.** In the year-over-year charts (Elbil, Jordvarme, Hus) the running month is a hollow point on a dashed segment, is left out of the dashed average line, and the tooltip says it is not finished. The curves are straight lines instead of smoothed splines that overshoot, and the same goes for the cumulative km/kWh, kr/km and cost-trend charts. Årsrapport labels the running year "til dato" and a year with home charges in fewer than 12 months "kun N mdr. data" (new `ev.months` field in the annual API).
- **Hus: Fordeling is merged into Forbrug.** The 12-month cost split (tiles, monthly stacked chart, donut) is now a second card under Husforbrug; the Fordeling sub-tab is gone and old `#hus/fordeling` links open Forbrug. Regning stays its own tab (a different job: matching the electricity bill).
- **Oversigt:** the expected electricity bill and any anomalies now come first, then the three cards, then tomorrow's price. Each card shows its comparisons as one compact line (`kWh  forrige mdr ↓5 % · sidste år ↓38 %`) instead of four, and consumption changes are no longer coloured green/red (less use is not automatically good); only price changes are.
- **Elbil > Ladninger:** the three Intern/Ekstern pie charts are gone (they showed 100/0 for most users) so the charge table is on the first screen; the summary heading now names the period it covers.
- **Hus split and Regning use home charging only.** External charges (public chargers) never pass the house meter and are not on the electricity bill, but were subtracted from the house meter and counted in the El-bil kWh/kr columns, so "rest" came out too small or negative. Hus > Forbrug, Hus > Regning and the cost model for the annual report now use internal charges only; the Elbil views and the annual EV totals still include external charges. Covered by a route test (an external charge must not change the house split or the bill).
- **The reason behind a tab dot is shown on its own page.** The yellow/red dot on Elbil/Jordvarme/Hus now has a banner at the top of that section (and a tooltip on the dot) with the anomaly text, also after the overview panel has been dismissed.
- **Hus: the parts now add up on screen.** When el-bil + jordvarme exceed the house meter (rest is clamped to 0) the percentages were >100 %; they are now shares of the three parts, with a note saying how many kWh the meters disagree by. Hus > Regning shows a visible explanation of the warning months instead of a hover-only tooltip.

### Fixed
- `charge-analytics` with `showZeroKwh=true` returned HTTP 500 when a charge had no `startedAt` (cancelled/error sessions). `DateHelper::splitChargeByDays` now falls back to the timestamp the charge does have and skips charges with none. Covered by `dev/test_date_helper.php` and a route check.

### Changed (API route layer)
- **One router, one route table.** `api.php` is now a few lines: `includes/routes.php` lists every action with its HTTP method, `includes/Router.php` dispatches (404 unknown action, **405 wrong method**, CSRF, central error handling). Mutation routes could previously be called with GET, which skipped the CSRF check (the verifier only looks at POST); they are now POST-only.
- **Shared helpers instead of copies in 30 route files:** `Http` (one error envelope, request body, parameter validators), `ApiCache` (serve/respond for the file cache), `ChargeInput` (kWh/price/date validation for external charges), `QueryBuilder::rowExists`. The nine mutation routes are rewritten on them with unchanged status codes and messages.
- `projectMonth` in the dashboard route no longer reads PHP globals (`global $daysElapsed ...`); it is a closure with explicit dependencies.
- **New `dev/test_routes.php`** (in `run_tests.php` and CI): starts the app on a seeded temporary database and exercises every route over HTTP, including session/CSRF, error cases and the Bearer-key endpoints (59 checks). `--record`/`--compare` fingerprints every GET answer; all 25 were byte-identical before and after this refactoring (the dashboard, whose values depend on the time of day, was compared field by field).

### Changed (tax rates moved out of the code)
- **The electricity tax now lives in `config/tax_rates.json`** (date-versioned elafgift for the full and the elvarme rate, the yearly kWh allowance, VAT, with source and notes) instead of PHP arrays in `Config.php`. A new rate is a data change; no PHP edit. `includes/TaxRates.php` loads and validates it: dates, ascending order, plausible magnitudes (a rate of 761 or VAT 25 instead of 1.25 is rejected), and an invalid or missing file is an error rather than a silent fallback to wrong amounts. `VAT_FACTOR` and `ELVARME_HIGH_KWH_YEAR` in `.env` still override the file; `TAX_RATES_FILE` points at another file.
- The file's fingerprint is part of the elspot-prices cache key, so an edited rate takes effect at once instead of after the 6-hour cache.
- `dev/test_config_rates.php` (25 checks) validates the file and the validator, the date lookup (including the 2025-12-31 / 2026-01-01 change) and still fails from 1 January when the new year's row is missing. Ten cost routes (dashboard, annual, monthly bill, all house-power views, charging optimisation, elspot-prices around the 2026 change) were compared with the previous version: identical.

### Changed (Bootstrap 5.1.3 -> 5.3.8)
- Bootstrap CSS and JS bundle upgraded (still Subresource-Integrity protected, still only the Modal and Tooltip APIs). Before/after computed styles of 11 views (all tabs and sub-tabs, the manage and sync modals) were compared; the look is pinned with a few `--bs-*` overrides at the top of `style.css`: the app's ink colour, the 5.1 corner radii, transparent table cells (5.3 paints them opaque white, which shows on the translucent cards) and the 2px rule between table head and body (opt-in in 5.3). All 38 Elbil DOM/chart fingerprints are identical after the upgrade.
- Visible differences that remain (all intended): `fw-semibold` now works (the class only exists from Bootstrap 5.2, so those labels were regular weight before), tables use the app's ink colour instead of `#212529`, and form-select borders are slightly lighter.

### Fixed (dates one day off)
- **"Denne måned" gave 31/10-29/11 in the URL hash instead of 1/11-30/11.** `getDateRange` formatted local-midnight dates with `toISOString()` (UTC), so both ends came out a day early; reloading from the hash restored the wrong range, and the range never reached the URL when it changed (see below). New `appUtils.ymd()` formats the local calendar date and replaces `toISOString().slice(...)` in the Elbil, Jordvarme and Hus tabs (also "today" between 00:00 and 02:00 Danish time). The charge filter itself used the date-picker text and was not affected.
- The date range now stays in the URL after it is restored from it.

### Changed (elbil.js split into parts)
- **`includes/elbil.js` (1,843 lines) is now nine files in `includes/elbil/`:** `core` (shared namespace `Elbil`: state, the parts, `esc`), `charges` (table, sort, pagination, summary, pie charts, edit/create/delete modals), `optimization`, `efficiency`, `analytics` (cost trend, vehicle comparison, provider stats), `yearly`, `manage`, `tou` and `main` (filters, drawer, vehicle/provider loading, sub-tab hook, `window.elbilApp`). Shared data lives in `Elbil.state`; the parts call each other as `Elbil.modules.<part>.<function>()`. The public API (`window.elbilApp`) is unchanged.
- **Verified identical:** before the split the Elbil tab was recorded in 41 DOM/chart fingerprints (default view, vehicle filter, quick filters, zero-kWh toggle, every sort column, analysis period, comparison sort, manage modal, all charts); after the split all 41 are identical. Interactions outside that recording were exercised in the browser: cost grouping, time-of-use period buttons, year metric toggle, filter drawer, reset, and a real create → edit → delete of a charge.
- **JS lint in CI:** ESLint `no-undef` (`dev/eslintrc.json`) fails the build on a name no part defines, which is the typical mistake when code moves between files.
- **Fixed on the way:** `SparkNav.updateHash` was called but never exported, so every quick-filter or date-range change threw a TypeError and the date range never reached the URL hash; it is exported now.

### Added (charging optimisation)
- **Elbil > Analyse > Ladeoptimering:** what your home charges cost at the hourly electricity prices, and what the same kWh would have cost in the cheapest hours the car was available for (3, 6 or 12 months): totals, a month table and the five charges with the largest potential, each with the cheapest time slots. `api.php?action=charge-optimization`.
- **Model (`includes/ChargeOptimizer.php`, 20 unit tests):** prices are the all-in hourly prices at the LOW elvarme tax rate (what EV charging pays). Actual = the charge's kWh spread over the hours it ran. Window = plug-in until the later of the charge end and plug-in + `EV_FLEX_HOURS` (default 12). Cheapest = the same kWh in the cheapest priced hours of the window at most `EV_MAX_KW` (default 7.4) kWh per hour, never worse than what was done. Charges that ended less than a window ago, or whose hours have no price, are skipped (and counted).
- The figures are spot-based, not your Monta invoice, and a potential rather than a promise (it depends on when the car is needed); the card says so and shows the assumptions.

### Security (strict Content-Security-Policy)
- **`script-src` no longer allows `'unsafe-inline'`.** The 18 inline `onclick` attributes became `data-action` attributes handled by one delegated listener (`includes/actions.js`); the three inline `<script>` blocks moved to `sync-modal.js`, `sw-register.js` and `config.js` (the server config is a JSON data block that `config.js` reads). CSP now also has `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, `frame-ancestors 'none'`. `style-src` keeps `'unsafe-inline'` (inline `style=""` attributes cannot run code); tightening that is separate work.
- **Guarded by tests:** `dev/test_routes.php` fails if the CSP header regains `unsafe-inline`/`unsafe-eval`, if `index.php` gains an inline `<script>` or event-handler attribute, or if a JS file builds one.
- **Two stored-XSS sinks fixed** found by injecting a hostile vehicle/provider name: the Elbil summary table and the ApexCharts legend (ApexCharts renders series names as HTML) used the name unescaped. Also escaped: the badge label for unknown charge states, the manage-modal message, and the sync modal's error text, labels and ids. The injected payload now shows as plain text in every view, and with the new CSP it could not have run anyway.

### Changed (route layer, part 2)
- **The `QueryBuilder` facade is gone.** It only forwarded to the repositories; its 74 call sites now use `VehicleRepository`, `ProviderRepository`, `ChargeRepository`, `PowerlogRepository`, `DateHelper`, `CacheHelper` and `Db`. A small class autoloader (`includes/autoload.php`, registered from `configuration.php`) loads `includes/<Class>.php` on first use, so files no longer `require` what they use.
- **The big routes are thin now; the logic lives in classes with no HTTP and no cache:** `DashboardSummary`, `AnnualSummary`, `MonthlyBill`, `HousePower` and `AnomalyDetector`. Each was verified identical to the previous version on the same seeded database (all modes and error cases for the house-power route).
- `AnomalyDetector::detect()` takes the date and the clock as parameters and has 13 unit tests (`dev/test_anomalies.php`): high/low/dead/stale detection and the day-5 gate, which had no tests before.
- The `Http` validators replace the local regex/closures; `elspot-prices` and `weather` now reject impossible dates such as 2026-02-30.

### Changed (electricity cost model moved to the server)
- **The elvarme cost model now exists once, in PHP** (`includes/ElvarmeCost.php`, assembled by `includes/CostEstimates.php`). It used to be duplicated in JavaScript (`elvarmeCost` in `app.js`, `includes/pricing.js`). The Dashboard, Årsrapport, Hus > Forbrug, Regning and Fordeling views now receive finished kr amounts (`cost` in their API responses) and only display them. 550 random cases were verified identical between the JS and PHP versions before the JS copies were removed, and every view was compared before/after on a seeded test instance.
- **Fewer requests from the browser:** the views no longer fetch hourly/daily prices and hourly consumption themselves (the dashboard dropped three requests, the others two each).
- `includes/ElspotPrices.php` is the price assembly extracted from `routes/get_elspot_prices.php` (output byte-identical); `HourlyConsumption::series()` is shared by the hourly-consumption route and the cost estimate.
- Raw Energi Data Service answers are cached per dataset and a 429 pauses all upstream calls; incomplete price answers are never cached.
- `MOCK_EDS=true` (dev only) serves deterministic synthetic prices, so the local test instance works offline.

### Fixed (mobile / iPhone)
- **Background photo missing on iPhone:** `background-attachment: fixed` on `body` is not supported by iOS Safari. The photo now sits on a fixed full-screen layer (`body::after`).
- **Unreadable text in dark mode:** the dark rules flipped the text colour to light but the cards stayed white (contrast 1.1-1.2:1 on headings and tables). The UI is now light-only (`color-scheme: light`); the half-built dark rules are removed. A proper dark theme would be a separate task.
- **Low-contrast text:** accent blue, light green/red, and grey text on the white cards measured 1.6-3.4:1. Text now uses stronger variants (WCAG AA 4.5:1), active tabs/buttons are white on dark blue, and the cards are slightly more opaque.

### Fixed
- **Regning/Fordeling: the current month's rate was diluted** by the unpublished future days of the month (rate 0). Month rates now average only days that have a price.
- **Hus > År til dato showed `NaN kWh`**: the first day of each year has no previous meter reading (NULL delta). It now counts as 0.
- **Elpris-prognosen and the EV price heatmap returned HTTP 400** (they sent `tariff=household`, which the route stopped accepting). They now use `tariff=low`.

### Changed (consumption per km)
- **New calculation of consumption per km** (`includes/EfficiencyCalc.php`, `routes/get_efficiency_stats.php`). Km come from the odometer at plug-in and kWh from the charges that refilled it; both are summed over a period before dividing. The old per-interval filter (kWh > 0 and km >= 20) is gone: it discarded a short interval together with the energy in its window, but that energy belonged to the drive before it, whose km were already counted. With a daily plug-in after short trips the old method showed 15.3 kWh/100 km for a car that really uses 18.0 (new: 17.7).
- **The card shows the last 30 / 90 days, the selected period and a month table per vehicle**, in kWh/100 km as well as km/kWh and kr/km, each with a confidence level (`lav` / `middel` / `høj`, with the reason as tooltip). The date range selects the drives that END in it, so a drive that started before the range is not lost. Charges without a vehicle are reported instead of silently left out, and unpriced charges are estimated at the average price of the priced ones (marked with `*`).
- **A single bad odometer reading no longer ruins the following interval.** A reading that falls outside its neighbours (a sensor that briefly reports 0) is rejected and counted; a real reset (several low readings in a row) is kept.
- Known limit of this step: the battery level at the start and end of a period is not accounted for, so a short period can be off by up to one battery's energy (hence the confidence level). The next step adds the SoC.
- Tests: `dev/test_efficiency.php` (40 checks, including 3000 random runs identical to the old method where its conditions hold).

### Fixed (dashboard trends)
- **"vs. forrige mdr" showed a false rise on the last days of a month when the previous month is shorter.** "Same period last month" is the first N days of last month (N = today's day-of-month), cut at that month's last day. On the 31st vs a 30-day month, or the 29th-31st vs February, that window holds fewer days than the current period, so flat usage looked like a rise of about 3 % (31 vs 30 days) up to about 9 % (31 March vs February). The capped window is now scaled to the same number of days (`DashboardWindow::comparableScale`), for kWh and cost of EV, heat pump, house and Rest, and for the pace the projection uses. Nothing changes on days where the window is not capped (28 of 31 days in most months), and the previous month's full-month figure is untouched.

### Changed (dashboard cards)
- **The Hus card now shows only "Rest"**: the house meter minus the heat pump and EV home charging (`rest` in the dashboard JSON, computed server-side for every period so the trends, projection and sparkline are consistent). EV and heat pump have their own cards, so the EV/VP split bar and the whole-house kWh are gone from it. Its ~kr is the Rest share of the elvarme model, and the bottom total's Rest uses the same figure.
- **Subscription in the monthly estimate.** `SUBSCRIPTION_PER_DAY` in `.env` (kr per day, e.g. `0.50`) is added to "Forventet elregning" as its own item (days in the month x the daily fee); the tooltip shows the total so far. Left at 0 the label stays "ekskl. abonnement". Monta's EV price already includes all tariffs and taxes, and the elvarme model has no per-kWh supplier surcharge, so the subscription is the only fixed addition.

### Fixed (dashboard meter window)
- **Heat pump and house month-to-date missed the 1st on the 31st of a 31-day month.** The meter series was fetched for the 30-day sparkline window (today - 29 days = the 2nd on the 31st), but the month starts on the 1st, so the first day was dropped from the month-to-date figures, daily average and projection (about 3 % too low on those days: Jan, Mar, May, Jul, Aug, Oct, Dec). The window now starts at the earlier of the month start and today - 29 days (`includes/DashboardWindow.php`, `dev/test_dashboard_window.php`).

### Added (charger events)
- **Events from the charger itself, via Home Assistant.** `receive_charger_event.php` (standalone like `receive_vehicle_data.php`, same API key) stores `plugged` / `charge_start` / `charge_end` / `unplugged` in the new `charger_events` table together with a snapshot of every car (SoC, odometer, cable sensor, the car's own timestamp). `api.php?action=charger-sessions` assembles them into sessions with the charger's own energy and average power. Car attribution from these signals is intentionally not decided yet — this step only collects data. Setup: `docs/home-assistant-charger-events.md`.
- `includes/ChargerEvents.php` (validation) and `includes/ChargerSessions.php` (assembly), tests in `dev/test_charger_events.php` against real data in `dev/fixtures/charger_events_sample.json`.

### Fixed (dashboard data logic)
- **Ongoing charging session counts.** A session that is still running has `stoppedAt = NULL` and was excluded; its end is now `COALESCE(stoppedAt, updatedAt, startedAt)`. `ev.charging_now` added to the dashboard JSON.
- **Projection no longer trusts a non-comparable anchor.** If this month's pace is far outside 0.5x..2x of the anchor's own month-to-date (e.g. a second car), the anchor loses weight and the run-rate takes over after day 3; last month is tried when last year is not comparable. `projection_basis` (`last_year` | `prev_month` | `run_rate`) is returned and shown as tooltip on "Forventet". Identical to the old result where the anchor is comparable.
- **Electricity cost estimate is consumption-weighted.** The estimate used one plain average of the month's prices for all consumption. The heat pump and the rest of the house are now priced with prices weighted by the hours they were actually used in (`api.php?action=hourly-consumption`, `includes/pricing.js`); falls back to the plain average if hourly consumption is unavailable. The estimate is labelled as such ("ekskl. abonnement" + tooltip).
- Removed the stray `routes/DatabaseManager.php` (a copy of `routes/get_dashboard_summary.php`).

### Added (dashboard data logic)
- `includes/Projection.php`, `includes/HourlyConsumption.php`, `includes/pricing.js` — pure, unit-tested building blocks; tests in `dev/test_projection.php`, `dev/test_hourly_consumption.php`, `dev/test_pricing.js`.
- `routes/get_hourly_consumption.php` (`action=hourly-consumption`), `PowerlogRepository::getReadings()`.

### Added
- `cron/migrate_charges_utc.php` — migration script to convert all `charges` table timestamps from mixed timezone offsets (`+01:00`, `+02:00`) to standardized UTC Z-suffix format. Supports `--commit` and dry-run mode.
- `_toUtcZ()` helper in `cron/update_monta_data.php` — normalizes incoming Monta API timestamps to UTC Z-suffix at ingestion time.

### Changed
- **Datetime standardization**: All datetime columns in `charges` and `ext_charges` now use consistent UTC Z-suffix format (`YYYY-MM-DDTHH:MM:SSZ`). SQLite `DATE()` handles this natively, enabling SQL-side filtering.
- `getCharges.php` — removed `strftime()` reformatting in UNION query; timestamps are returned as stored (already ISO 8601).
- `getDashboardSummary.php` — simplified ext_charges filtering using `DATE()` comparisons instead of PHP `strtotime()` loop.
- `getProviderStats.php` — replaced PHP-side date/vehicle filtering with SQL WHERE clauses using `DATE()`.
- `getEfficiencyStats.php` — replaced PHP-side ext_charges date filtering with SQL `DATE()` filter.
- `getAnomalyStats.php` — use `DATE(datetime)` in SQL instead of `strtotime()` extraction.
- `getHousePowerData.php` — `_extEvByKey()` now uses SQL `DATE()` bounds instead of PHP loop filtering.
- `getMonthlyBillData.php` — use `DATE(datetime) >=` for ext_charges query.
- `getAnnualSummary.php` — extract year from datetime string directly instead of `strtotime()`.
- `getChargeCompare.php` — updated comments; logic unchanged (still uses `strtotime` for local-tz month grouping).
- `QueryBuilder::getCostTrend()` — ext_charges date filtering moved to SQL with `DATE()`.
- `QueryBuilder::getCostStatistics()` — ext_charges aggregation now done entirely in SQL (single query with SUM/COUNT/MIN/MAX).
- `QueryBuilder::getVehicleCostComparison()` — ext_charges date filtering moved to SQL.

### Fixed
- `getMonthlyBillData.php` — fixed duplicate/missing months caused by `strtotime("-N months")` on day 31 (anchored to 1st of month).
- `QueryBuilder::fileCacheInvalidatePattern()` — replaced inline cache code with `QueryBuilder::fileCacheRead/fileCacheWrite` (key prefix `analytics_`). Consolidated double catch block.
- `getVehicleComparison.php` — consolidated redundant `PDOException` + `\Throwable` catch into single `\Throwable` catch. Removed trailing `?>`.
- `cron/update_monta_data.php` — replaced all `die()` calls with `error_log()` + `exit(1)`. `getChargingData()` now returns `null` on HTTP 429; caller writes error to `sync_log` and exits cleanly.
- `index.php` — pinned CDN versions: Chart.js@4.4.6, chartjs-plugin-datalabels@2.2.0, chartjs-adapter-date-fns@3.0.0, flatpickr@4.6.13, ApexCharts@3.54.0. Updated all stale `?v=` strings to `20260528`.
- `sw.js` — bumped cache name from `sparkspend-v2` to `sparkspend-v3` to force clients to pick up new pinned CDN assets.

### Fixed
- `getChargeCompare.php`, `getAnnualSummary.php` — `WHERE startedAt IS NOT NULL AND stoppedAt IS NOT NULL` prevents PHP TypeError from null timestamps causing blank charts.
- All 22 production endpoints — changed `catch (Exception $e)` to `catch (\Throwable $e)` so PHP TypeErrors are caught and return a proper JSON error response instead of an empty HTTP 200 body.
- All 7 mutation endpoints (`createExCharge`, `updateExtCharge`, `deleteExtCharge`, `updateInternalCharge`, `createProvider`, `updateProvider`, `deleteProvider`) — call `QueryBuilder::fileCacheInvalidatePattern()` to prevent stale cached reads after a write.

---

## [2026-03-16] — Årsrapport + PWA

### Added
- Annual summary view (`getAnnualSummary.php` + `includes/annual.js`).
- Progressive Web App: `manifest.json`, `sw.js`, icon generation via `cron/generate_icons.php`.
- Global sync status indicator and "Sync nu" button (`getSyncStatus.php`, `triggerSync.php`).

---

## [2026-03-15] — Hus-faneblad

### Added
- Hus tab with stacked consumption chart and monthly bill overview.
- `cron/sync_housepowerlog_data.php` — MySQL → SQLite sync with Wh→kWh normalisation.
- House power dashboard card with sparkline, kWh/day and trend badge.

---

## [2026-03-12] — Jordvarme estimeret pris

### Added
- `activeCostMap` + `fetchElCosts()` in `jordvarme.js` — estimated electricity cost for all four heatpump modes.

---

## [2026-02-01] — EV flerårigt sammenligningsvisning

### Added
- `getChargeCompare.php` + year-over-year comparison chart with year chips.
- `QueryBuilder::splitChargeByDays()` — proportional kWh distribution across midnight.

### Fixed
- `ext_charges.datetime` stored as pseudo-UTC; `createExCharge.php` and `updateExtCharge.php` now store genuine UTC.
