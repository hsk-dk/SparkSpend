# Changelog

All notable changes to SparkSpend are listed here in reverse chronological order.

---

## [Unreleased]

### Changed (API route layer)
- **One router, one route table.** `api.php` is now a few lines: `includes/routes.php` lists every action with its HTTP method, `includes/Router.php` dispatches (404 unknown action, **405 wrong method**, CSRF, central error handling). Mutation routes could previously be called with GET, which skipped the CSRF check (the verifier only looks at POST); they are now POST-only.
- **Shared helpers instead of copies in 30 route files:** `Http` (one error envelope, request body, parameter validators), `ApiCache` (serve/respond for the file cache), `ChargeInput` (kWh/price/date validation for external charges), `QueryBuilder::rowExists`. The nine mutation routes are rewritten on them with unchanged status codes and messages.
- `projectMonth` in the dashboard route no longer reads PHP globals (`global $daysElapsed ...`); it is a closure with explicit dependencies.
- **New `dev/test_routes.php`** (in `run_tests.php` and CI): starts the app on a seeded temporary database and exercises every route over HTTP, including session/CSRF, error cases and the Bearer-key endpoints (59 checks). `--record`/`--compare` fingerprints every GET answer; all 25 were byte-identical before and after this refactoring (the dashboard, whose values depend on the time of day, was compared field by field).

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
