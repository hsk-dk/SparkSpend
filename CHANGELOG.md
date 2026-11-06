# Changelog

All notable changes to SparkSpend are listed here in reverse chronological order.

---

## [Unreleased]

### Changed (UX review)
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
