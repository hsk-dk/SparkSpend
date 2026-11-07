# Time in SparkSpend: which column holds which clock

Two clocks are in use, and mixing them is the commonest way to get an answer that is an hour (or a day) off.
The rule: **events and charges are stored in UTC; the meters are stored as local wall-clock time.** Convert at
the edge, never compare a UTC column with a local one.

The app's own time zone is `TIMEZONE` in `.env` (default `Europe/Copenhagen`); `date_default_timezone_set` is
called once in `includes/configuration.php`, so `date()` and `strtotime()` mean local time everywhere.

| Data | Where | Format | Clock |
|---|---|---|---|
| Monta charges | `charges.createdAt / updatedAt / cablePluggedInAt / startedAt / stoppedAt` | `2026-03-01T09:05:00Z` | **UTC**. Monta sends `+01:00` / `+02:00`; `ChargeImporter::toUtcZ` normalises it, so plain string comparison orders correctly |
| External charges | `ext_charges.datetime` | `2026-03-01T09:05:00Z` | **UTC**. The form sends local time; `ChargeInput::validate` converts it |
| Odometer / plug-in readings | `vehicle_charges.cablePluggedInAt` | `2026-03-01T09:05:00Z` | **UTC**, set by the server when Home Assistant's call arrives |
| Charger events | `charger_events.eventAt`, `receivedAt` | `2026-03-01T09:05:00Z` | **UTC** (`ChargerEvents::validate`) |
| Heat pump and house meters | `powerlogjord.logdate`, `powerloghus.logdate` | `2026-03-01 10:00:00` | **Local** wall-clock. The hourly bucket label of the MySQL logger (`DATE_FORMAT(logdate, '%Y-%m-%d %H:00:00')`), the value is the cumulative counter (MAX in that hour) |
| Per-device energy | `device_energy.day` | `2026-03-01` | **Local date**; Home Assistant sends yesterday's date in its own time zone at 00:10 |
| Sync status | `sync_log.updated_at`, `last_sync_timestamp` | `2026-03-01 10:00:00` | **Local**. For the meters the watermark is the newest bucket label (local) |
| Electricity prices | Energi Data Service `HourDK` | local hour | **Local**, one price per local hour |

## What follows from it

- **An hour of meter data.** The reading labelled `H` is the counter at the end of hour `H`; consumption *during*
  hour `H` is the difference to the previous label (`HourlyConsumption`). Daily figures are differences of the
  readings of consecutive days (`PowerlogRepository::lagDelta`). A counter that goes down (a reset) is an
  unknown interval and counts as 0.
- **Putting a charge on the meter's clock.** A charge's UTC start and stop are converted to local time before
  they are subtracted from the house meter: per hour by `HourlyConsumption::spreadCharge`, per day by
  `DateHelper::splitChargeByDays` (a charge that spans midnight is split proportionally to the time in each day).
- **Daylight saving time.** Hour labels are wall-clock, so on the autumn night the same label can occur twice and
  on the spring night one is missing. `HourlyConsumption` does its arithmetic on the labels as if they were UTC,
  which avoids the clock jump, and `DashboardSummary` measures "how much of today has passed" in real seconds
  (a day can be 23 or 25 hours).
- **Comparing strings.** `YYYY-MM-DDTHH:MM:SSZ` and `YYYY-MM-DD HH:MM:SS` order correctly as strings *within*
  their own format. They do not order against each other: `'2026-03-01T09:00:00Z' > '2026-03-01 10:00:00'`
  (because `T` sorts after a space). Convert first.
- **New data.** Store events and anything a person or an API timestamps in UTC `...Z`; store only a meter's
  own hourly labels as local.
