# SparkSpend

Personligt energi-dashboard til overvågning af EV-opladning, varmepumpe og husholdningsforbrug.
Bygget med plain PHP / SQLite og en vanilla-JS frontend (Chart.js, ApexCharts, Bootstrap).

---

## Funktioner

| Faneblad | Indhold |
|---|---|
| **Oversigt** | Dashboard med tre kort (EV, Jordvarme, Hus), sparklines og trend-badges |
| **Elbil** | Opladningstabel, omkostningsanalyse, år-over-år sammenligning, udbyder- og køretøjsadministration |
| **Jordvarme** | Dagligt/månedligt varmepumpeforbrug, vejr-normalisering (HDD), estimeret elpris |
| **Hus** | Stablet forbrugsoversigt (jordvarme + EV + restforbrug), månedlig regningsoversigt |

Datakilde: Monta API (EV), MySQL (varmepumpe + hus via eksisterende logger), Energi Data Service (spotpris), Open-Meteo (vejr).

---

## Krav

- PHP 8.1+, SQLite3-udvidelse, cURL-udvidelse
- MySQL-adgang til varmepumpe/huslogger (kun til cron-sync)
- Webserver (Apache/Nginx) eller `php -S`

---

## Opsætning

```bash
# 1. Klon repositoriet
git clone <repo-url> sparkspend
cd sparkspend

# 2. Kopiér og udfyld .env
cp .env.example .env
# Rediger .env med MONTA_CLIENT_ID, MONTA_CLIENT_SECRET, DB-stier mv.

# 3. Opret data-mappe (hvis ikke allerede til stede)
mkdir -p data

# 4. Initalisér databaser (køres automatisk første gang via DatabaseManager)

# 5. Kør første Monta-sync manuelt
php cron/update_monta_data.php
```

---

## Cron-jobs

Tilføj til `/etc/crontab` eller cPanel's cron-manager:

```cron
# Monta EV-data — hvert 15. minut
*/15 * * * * php /sti/til/sparkspend/cron/update_monta_data.php >> /sti/til/logs/monta.log 2>&1

# Varmepumpe-sync — hvert 5. minut (eller justér HEATPUMP_SYNC_INTERVAL i .env)
*/5  * * * * php /sti/til/sparkspend/cron/sync_heatpump_data.php >> /sti/til/logs/heatpump.log 2>&1

# Husforbrug-sync — hvert 5. minut
*/5  * * * * php /sti/til/sparkspend/cron/sync_housepowerlog_data.php >> /sti/til/logs/hus.log 2>&1
```

---

## .env nøgler (vigtigste)

| Nøgle | Beskrivelse | Standard |
|---|---|---|
| `MONTA_CLIENT_ID` | Monta OAuth klient-ID | — |
| `MONTA_CLIENT_SECRET` | Monta OAuth klient-hemmelighed | — |
| `CHARGING_DB_PATH` | Sti til charges.db | `data/charging_data.db` |
| `POWERLOG_DB_PATH` | Sti til powerlog_data.db | `data/powerlog_data.db` |
| `CACHE_DIR` | Mappe til filcache (standard: `/tmp`) | `sys_get_temp_dir()` |
| `ELSPOT_AREA` | Prisområde (`DK1` eller `DK2`) | `DK2` |
| `ELSPOT_GLN` | Netselskabets GLN-nummer | — |
| `WEATHER_LAT` | Breddegrad (decimal) | — |
| `WEATHER_LON` | Længdegrad (decimal) | — |
| `TIMEZONE` | PHP-tidszone | `Europe/Copenhagen` |

Se `.env.example` for fuld liste.

---

## Dataintegritetstjek

```bash
php dev/check_data_integrity.php
```

Tjekker både `powerlog_data.db` (jordvarme/hus) og `charges.db` (EV) for NULL-værdier, ugyldige rækker og referenceintegritet.
