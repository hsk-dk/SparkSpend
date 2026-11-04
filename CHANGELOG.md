# Changelog

All notable changes to SparkSpend are listed here in reverse chronological order.

---

## [Unreleased]

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
