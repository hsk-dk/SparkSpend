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

## API-opbygning

Al JSON-trafik går gennem `api.php`, som kun slår handlingen op i ruttetabellen `includes/routes.php` og kalder `Router`. Routeren tjekker HTTP-metoden (405), CSRF på mutationer (403) og fanger fejl centralt (500, uden interne detaljer medmindre `DEBUG=true`).

Ny rute: tilføj en linje i `includes/routes.php` (`'method' => 'GET'|'POST'`, `'csrf' => true` på mutationer) og en fil i `routes/`. Rutefilen bruger:

- `Http::body()`, `Http::int()`, `Http::str()`, `Http::query()` og validatorerne `Http::isDate/isMonth/isYear` til input,
- `Http::fail($besked, $status)` til fejl (altid `{"success": false, "error": "…"}`) og `Http::send()` til svar,
- `ApiCache::serve($nøgle, $ttl)` øverst og `ApiCache::respond($nøgle, $data, $gem)` nederst for filcachede svar.

`dev/test_routes.php` starter appen på en seedet midlertidig database og kalder alle ruter over HTTP (session og CSRF med). Med `--record`/`--compare` kan man bevise, at en refaktorering ikke har ændret et eneste svar.

## Frontend og CSP

Elbil-fanen er delt i dele i `includes/elbil/` (indlæses i den rækkefølge, `index.php` angiver: `core` først, `main` sidst). Delt tilstand er `Elbil.state`; delene kalder hinanden som `Elbil.modules.<del>.<funktion>()`; indgangen udadtil er stadig `window.elbilApp`. Ny funktion i en del skal med i dens eksportliste nederst i filen, hvis en anden del skal kalde den. CI kører ESLint (`dev/eslintrc.json`), som fanger navne, ingen del definerer.

Siden har ingen inline scripts og ingen inline event-handler-attributter (CSP `script-src` uden `'unsafe-inline'`). Klik i markup skrives som `data-action="navn"` med `data-*`-argumenter og håndteres i `includes/actions.js`; en ny handling tilføjes som ét opslag i `handlers`-tabellen dér. Serverkonfigurationen til JS ligger som en JSON-datablok i `index.php` og læses af `includes/config.js`. Data fra serveren (navne, fejlbeskeder) skal escapes med `appUtils.escapeHtml` (eller `textContent`), før de indsættes i `innerHTML`.

## Årlig opdatering af afgiftssatser

Elafgift (almindelig og elvarmesats), kWh-kvoten til den fulde sats og momsen ligger i `config/tax_rates.json`, ikke i koden. Hver række gælder fra sin `from`-dato til næste række, så historik prissættes med datidens sats. Når en ny sats er offentliggjort (skat.dk): tilføj en række i filen, kør `php dev/run_tests.php`, og deploy filen. Filen valideres (datoer, rækkefølge, rimelige tal, moms som 1,25 og ikke 25), og en ugyldig fil giver en tydelig fejl i stedet for stille forkerte beløb. `test_config_rates.php` fejler fra 1. januar, hvis der mangler en række for det nye år. Cachede priser opdateres med det samme, fordi filens fingeraftryk er en del af cache-nøglen.

## Tests

```bash
php dev/run_tests.php          # alle dev/test_*.php og dev/test_*.js (Node kræves til JS-testen)
php dev/run_tests.php pricing  # kun tests med "pricing" i filnavnet
```

Kører også i GitHub Actions (`.github/workflows/tests.yml`) ved push og pull requests. `test_config_rates.php` fejler fra 1. januar, hvis afgiftstabellerne i `includes/Config.php` ikke har en række for det nye år.

## Lokal testinstans (syntetiske data, uden Authentik)

```bash
cp .env.dev.example .env.dev
SPARKSPEND_ENV_FILE=.env.dev php dev/seed_dev.php     # opretter data-dev/*.db
SPARKSPEND_ENV_FILE=.env.dev php -S localhost:8080
```

`SPARKSPEND_ENV_FILE` peger appen på en anden env-fil end `.env`, og `DEV_INSECURE_COOKIE=true` tillader session-cookie over `http://localhost`. Seed-scriptet bruger kun genererede data. `.claude/launch.json` starter instansen direkte fra Claude.

## Cron-jobs

Tilføj til `/etc/crontab` eller cPanel's cron-manager:

> **Erstat `/sti/til/sparkspend` med den rigtige sti** på serveren (og tjek logfilen bagefter). Med `EDS_WEB_FETCH=false` er `warm_prices.php` det eneste, der henter elpriser. Peger den på en sti, der ikke findes (`Could not open input file: /sti/til/...` i prices.log), bliver prisernes cache forældet, og Oversigtens "Elpris i morgen" og alle kr-estimater forsvinder uden anden fejl.

```cron
# Monta EV-data — hvert 15. minut
*/15 * * * * php /sti/til/sparkspend/cron/update_monta_data.php >> /sti/til/logs/monta.log 2>&1

# Varmepumpe-sync — hvert 5. minut (eller justér HEATPUMP_SYNC_INTERVAL i .env)
*/5  * * * * php /sti/til/sparkspend/cron/sync_heatpump_data.php >> /sti/til/logs/heatpump.log 2>&1

# Husforbrug-sync — hvert 5. minut
*/5  * * * * php /sti/til/sparkspend/cron/sync_housepowerlog_data.php >> /sti/til/logs/hus.log 2>&1

# Elpris-cache (Energi Data Service rate-limiter hårdt) — hvert 30. minut, og kør den én gang manuelt efter deploy
*/30 * * * * php /sti/til/sparkspend/cron/warm_prices.php >> /sti/til/logs/prices.log 2>&1
```

`warm_prices.php` henter elpriserne for de seneste 3 kalenderår og venter igennem Energi Data Services 429-pauser, så siderne (Regning, Årsrapport, Hus, Oversigt) aldrig selv skal vente på dem. På en kold cache tager første kørsel nogle minutter. Indtil da viser Regning "—" for de måneder, der mangler priser.

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
| `VAT_FACTOR` | Overstyrer momsen fra `config/tax_rates.json` (sjældent nødvendigt) | fra filen |
| `ELVARME_HIGH_KWH_YEAR` | Overstyrer kWh-kvoten til fuld elafgift | fra filen |
| `TAX_RATES_FILE` | Anden satsfil (test eller staging) | `config/tax_rates.json` |
| `EV_FLEX_HOURS` | Ladeoptimering: timer efter tilslutning, bilen regnes for tilgængelig | `12` |
| `EV_MAX_KW` | Ladeoptimering: laderens effekt i kW | `7.4` |
| `AUTO_MIGRATE` | Kør DB-migrationer automatisk ved web-requests (højst en gang i timen) | `true` |
| `EDS_WEB_FETCH` | `false` = kun `cron/warm_prices.php` kalder Energi Data Service | `true` |
| `VEHICLE_API_KEY` | Bearer-nøgle til `receive_vehicle_data.php` og `receive_charger_event.php` (uden nøgle svarer de 503) | — |

Se `.env.example` for fuld liste.

### Anbefalet produktionsopsætning

- `AUTO_MIGRATE=false` og kør `php migrate.php` som en del af deploy: så ændres databaseskemaet aldrig af en tilfældig brugeranmodning. `php migrate.php --status` viser, hvad der er anvendt.
- `DEBUG=false`, `VEHICLE_API_KEY` sat til en lang tilfældig værdi, og `EDS_WEB_FETCH=false` når `cron/warm_prices.php` kører i cron.

---

## Dataintegritetstjek

```bash
php dev/check_data_integrity.php
```

Tjekker både `powerlog_data.db` (jordvarme/hus) og `charges.db` (EV) for NULL-værdier, ugyldige rækker og referenceintegritet.
