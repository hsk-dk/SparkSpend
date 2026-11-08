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

Kører også i GitHub Actions (`.github/workflows/tests.yml`) ved push og pull requests. **Kør `bash dev/ci-local.sh` før du pusher**: den kører PHP-syntaks, testene og PHPStan i en Linux-container med PHP 8.3 og de samme udvidelser som CI (Docker kræves). En lokal PHP mangler ofte en udvidelse (her `mbstring`), og så kan en test bestå lokalt og fejle i CI. `test_config_rates.php` fejler fra 1. januar, hvis afgiftstabellerne i `includes/Config.php` ikke har en række for det nye år.

### Golden-test ved refaktorering af beregninger

`bash dev/golden/capture.sh before <mappe-med-databaser> ...` kører dashboardet under et frosset ur (libfaketime i Docker) på flere tidspunkter (månedsskifte, sommertid, skudår ...) og gemmer svarene i `dev/golden/out/before/` (ignoreret af git, de er afledt af produktionsdata). Ret koden, kør igen med `after`, og `diff -r dev/golden/out/before dev/golden/out/after` skal være tom. Mappen skal indeholde `charging_data.db` og `powerlog_data.db`: en kopi af produktionens, eller `SEED_DIR=<mappe> php dev/seed_dev.php`.

### Statisk analyse (PHPStan)

```bash
docker run --rm -v "$PWD:/app" -w /app ghcr.io/phpstan/phpstan:2.3.5 analyse -c phpstan.neon
```

Niveau 4 med en baseline (`phpstan-baseline.neon`): de fund, der fandtes da den blev indført, står dér, så CI kun fejler på *nye* problemer. Ret en baseline-post og slet den fra filen; tilføj aldrig til den for at få CI grønt. De fleste poster er falske alarmer (PHPStan forstår ikke closures med reference). Hvilket ur hver kolonne bruger, står i `docs/TIME.md`.

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

**Sådan opfører cron-jobbene sig.** De fire jobs deler én runner (`includes/CronJob.php`): ét job kører ad gangen (en `flock` i `CACHE_DIR`, som operativsystemet frigiver, hvis processen dør; en igangværende kørsel af samme job gør, at den næste logger "Already running" og stopper). Exit-kode `0` = ok, `1` = fejlet, `2` = Monta rate-limit eller netværk nede (næste kørsel prøver igen). En fejl står i `cron/cron.log` og i `sync_log` (vandstanden bevares). Et job, der intet finder, skriver ingenting i loggen: om de kører, ses i `/health.php`, Oversigten og `php doctor.php`. Jobbene kan kun startes fra kommandolinjen.

**Logfiler roteres automatisk.** `cron/cron.log` (skrives af de tre sync-jobs) roteres af appen selv, når den overstiger `LOG_MAX_BYTES` (standard 5 MB): `cron.log` → `cron.log.1` → … og de ældste slettes efter `LOG_KEEP` filer (standard 3). Det kræver ingen opsætning. Logfiler, som cron skriver med en shell-omdirigering (`prices.log`, PHP's fejllog), roteres ikke af appen; til dem ligger der en logrotate-fil i `cron/logrotate.sparkspend` (kopiér til `/etc/logrotate.d/`, instruktion i filen).

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
| `LOG_MAX_BYTES` / `LOG_KEEP` | Rotér `cron/cron.log`, når den er større end så mange bytes; behold så mange roterede filer | `5242880` / `3` |
| `EDS_WEB_FETCH` | `false` = kun `cron/warm_prices.php` kalder Energi Data Service | `true` |
| `VEHICLE_API_KEY` | Bearer-nøgle til `receive_vehicle_data.php`, `receive_charger_event.php` og `receive_device_energy.php` (uden nøgle svarer de 503) | — |
| `HEALTH_KEY` | Bearer-nøgle til `health.php` (skrivebeskyttet datafriskhed til Uptime Kuma; uden nøgle svarer den 503). Brug en anden værdi end `VEHICLE_API_KEY` | — |

Se `.env.example` for fuld liste.

### Anbefalet produktionsopsætning

- `AUTO_MIGRATE=false` og kør `php migrate.php` som en del af deploy: så ændres databaseskemaet aldrig af en tilfældig brugeranmodning. `php migrate.php --status` viser, hvad der er anvendt.
- `DEBUG=false`, `VEHICLE_API_KEY` og `HEALTH_KEY` sat til hver sin lange tilfældige værdi, og `EDS_WEB_FETCH=false` når `cron/warm_prices.php` kører i cron.
- Kør `php doctor.php` efter hver udrulning. Den ændrer ikke noget, men kontrollerer `.env`, mapper, ventende migreringer, at de fire cron-jobs er planlagt med stier der findes, og at hver datakilde er frisk. Exit-kode 1 ved fejl (`--no-cron` springer crontab-tjekket over).

### Sikkerhed

Appen har intet login selv: Authentik foran den er login'et. Det holder kun, hvis intet andet kan nå webserveren. Tre lag, hvert for sig:

1. **Webserveren (vigtigst).** Appen ligger som et git-checkout direkte i webroden, så uden regler udleverer nginx `.env` (nøgler og adgangskoder), `.git`, `data/*.db`, `cron/*.log` og kildekoden, og *kører* enhver `.php`-fil, der bliver bedt om. `docs/nginx-sparkspend.conf` er den færdige server-blok (testet mod nginx og php-fpm i containere): den lader kun sidens egne filer og syv PHP-indgange være tilgængelige. Efter `nginx -t` og reload: `php doctor.php --probe=http://<serverens-ip> --host=<appens værtsnavn>`; den henter de følsomme filer og skal melde dem alle som afvist. Probe serverens egen adresse, ikke den offentlige (den viser kun login-redirect).
2. **Appen selv: `TRUSTED_PROXIES`.** Sæt IP'en på den maskine, der sender forespørgslerne til webserveren (din front-proxy, hos os `10.0.1.180`), i `.env`: `TRUSTED_PROXIES=10.0.1.180`. Det er den adresse, webserveren ser som klient (`$remote_addr`), ikke Authentiks. Så svarer siderne og API'et `403` til alle andre afsendere, også på det interne net. Kun `REMOTE_ADDR` læses, aldrig `X-Forwarded-For`. Home Assistant og overvågningen bruger deres Bearer-nøgle og berøres ikke.
3. **Filerne.** Rutefilerne, cron-scripts, `migrate.php` og `doctor.php` nægter at køre uden for appens opstart eller kommandolinjen, så en hentning over HTTP er et `404`, ikke en kørsel. (Det er en reserve; regel 1 er den rigtige lås.)

Har `.env` kunnet hentes af andre end dig, så skift de hemmeligheder, den indeholder: Monta-nøglen, MySQL-adgangskoden, `VEHICLE_API_KEY`, `HEALTH_KEY` og `ADMIN_KEY`.

### Overvågning med Uptime Kuma

`health.php` svarer `200` når alle datakilder er friske og `503` når en er forældet, har fejlet eller aldrig har leveret (Monta, jordvarme, hus, husets måler, elpriser). Grænserne står i `Freshness::MAX_AGE`; tjek på enhedsenergi fra Home Assistant tæller kun, når HA er begyndt at sende.

1. Sæt `HEALTH_KEY` i `.env` (fx `openssl rand -hex 24`).
2. Undtag `/health.php` fra Authentik, som `receive_*.php`, eller lad Uptime Kuma kalde appen på det interne netværk.
3. I Uptime Kuma: ny monitor, type **HTTP(s) - Keyword**, URL `https://<host>/health.php`, interval 300 s, nøgleord `"status":"ok"`, og under *Headers*: `{"Authorization": "Bearer <HEALTH_KEY>"}`. Accepterede statuskoder `200-299`. Så bliver monitoren rød i det øjeblik en kilde bliver forældet, og svaret viser hvilken.

Samme dom vises som en gul advarsel øverst i Oversigten.

---

## Dataintegritetstjek

```bash
php dev/check_data_integrity.php
```

Tjekker både `powerlog_data.db` (jordvarme/hus) og `charges.db` (EV) for NULL-værdier, ugyldige rækker og referenceintegritet.
