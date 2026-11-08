# Backup og restore

To SQLite-databaser indeholder alt, der ikke kan hentes igen:

| Fil | Indhold | Kan genskabes uden backup? |
|---|---|---|
| `data/charging_data.db` | Opladninger, køretøjer, udbydere, **manuelt indtastede (eksterne) opladninger**, parring af opladning og bil | Kun delvist: Monta-historikken kan hentes igen, men egne rettelser, eksterne opladninger og bilparring kan ikke |
| `data/powerlog_data.db` | Varmepumpe- og husforbrug, kopieret fra MySQL-loggeren | Kun så langt tilbage, som MySQL-loggeren selv gemmer data (**tjek det**) |

`.env` (nøgler og adgangskoder) er **ikke** med i databasebackuppen. Den tages separat og krypteret, se nedenfor.

## Hvad scriptet gør

`cron/backup.php` tager et konsistent øjebliksbillede af hver database med SQLites `VACUUM INTO`. Det virker, mens appen og cron kører (databaserne bruger WAL; en almindelig `cp` kan få en halv skrivning og springer `-wal`-filen over). Derefter:

1. Øjebliksbilledet integritetstjekkes (`PRAGMA integrity_check`) og komprimeres.
2. `manifest.json` gemmer størrelser, SHA-256 og antal anvendte migreringer.
3. Backuppen **verificeres igen** (checksum, udpakning, integritet), før scriptet melder succes.
4. Gamle backuper fjernes: de nyeste 14 dage plus den nyeste fra hver af de seneste 6 måneder (`BACKUP_KEEP_DAILY` / `BACKUP_KEEP_MONTHLY`).

En backupmappe får først sit endelige navn (`ÅÅÅÅMMDD-TTMMSS`, UTC), når alt er færdigt. En mappe uden `manifest.json` er aldrig en gyldig backup.

## Opsætning

1. Vælg en mappe **uden for webroden**, f.eks. `/var/backups/sparkspend`, ejet af den bruger, cron kører som. Mappen oprettes med rettighederne `0700`.
2. I `.env`:
   ```
   BACKUP_DIR=/var/backups/sparkspend
   # BACKUP_KEEP_DAILY=14
   # BACKUP_KEEP_MONTHLY=6
   ```
   Scriptet nægter at køre, hvis `BACKUP_DIR` mangler, er relativ eller ligger i appens egen mappe.
3. Cron, én gang i døgnet (kør det som samme bruger som de andre jobs):
   ```cron
   # Backup af databaserne — hver nat kl. 03:15
   15 3 * * * php /var/www/monta/cron/backup.php >> /var/www/monta/cron/backup.log 2>&1
   ```
4. **Kopiér backupmappen væk fra serveren** (anden maskine, NAS eller skylager). En backup på samme disk som databasen beskytter ikke mod diskfejl eller et tabt system. Det gør scriptet ikke selv, fordi destinationen afhænger af dit setup. Eksempel med `rsync` fra en anden maskine:
   ```bash
   rsync -a --delete-after server:/var/backups/sparkspend/ /mnt/nas/sparkspend-backup/
   ```

Tjek at det kører:

```bash
php cron/backup.php --status     # nyeste backup; exit 1 hvis ingen eller ældre end 36 timer
```

Lad f.eks. Uptime Kuma (push-monitor) eller en egen cron kalde `--status`. Appens egen `/health.php` kigger ikke på backup endnu.

## Backup af hemmeligheder (`.env`)

`.env` indeholder Monta-nøgler, MySQL-adgangskode, `HEALTH_KEY`, `VEHICLE_API_KEY` og `ADMIN_KEY`. Den skal kunne genskabes, men **må ikke ligge ukrypteret ved siden af databasebackuppen**.

```bash
# Kryptér (spørger efter en adgangskode, som du opbevarer i din adgangskodemanager)
openssl enc -aes-256-cbc -pbkdf2 -salt -in /var/www/monta/.env -out sparkspend-env-$(date +%F).enc

# Dekryptér
openssl enc -d -aes-256-cbc -pbkdf2 -in sparkspend-env-2026-11-08.enc -out .env
```

Tag en ny kopi, hver gang `.env` ændres, og efter nøglerotation. Adgangskoden til krypteringen må **ikke** ligge på serveren.

## Genskab

Brug altid først en afprøvning, så du ved, at backuppen virker, før du har brug for den.

### 1. Kun tjek en backup (ændrer intet)

```bash
php cron/restore_backup.php latest --verify
php cron/restore_backup.php /var/backups/sparkspend/20261108-031500 --verify
```

### 2. Genskab til en anden mappe (restoretest)

```bash
php cron/restore_backup.php latest --to=/tmp/restoretest
```

De levende databaser røres ikke. Åbn filerne i `/tmp/restoretest`, eller peg en testinstans på dem (`CHARGING_DB_PATH` / `POWERLOG_DB_PATH` i en separat env-fil via `SPARKSPEND_ENV_FILE`), og kontrollér, at dashboardet viser forventede tal.

### 3. Genskab over de levende databaser

1. **Sæt cron på pause** (kommentér linjerne i crontab ud), og vent til kørende jobs er færdige. Scriptet nægter at køre, hvis et job stadig holder sin lås.
2. ```bash
   php cron/restore_backup.php latest --force
   ```
   De nuværende filer flyttes til `<fil>.before-restore-<tid>` (de slettes aldrig), og gamle `-wal`/`-shm`-filer flyttes væk, så de ikke kan blive afspillet oven på den genskabte database.
3. ```bash
   php migrate.php           # hvis backuppen er ældre end den nuværende kode
   php doctor.php
   ```
4. Genoptag cron. Monta- og husloggeren henter selv det manglende siden backuppen.
5. Når alt er set efter, slet `*.before-restore-*`.

### Hvis hele serveren er tabt

1. Installér PHP 8.1+ med `pdo_sqlite`, `sqlite3`, `curl`, `zlib` og webserveren (`docs/nginx-sparkspend.conf`).
2. Hent koden (`git clone`), dekryptér `.env` fra den krypterede kopi.
3. Hent den nyeste backupmappe fra den eksterne kopi, og kør `php cron/restore_backup.php <mappe> --force`.
4. `php migrate.php`, `php doctor.php`, indlæs crontab, og kør `php cron/warm_prices.php` én gang.

## Evidens for restoretest

Notér hver gang, du har afprøvet en genskabelse. Det er først en backup, når den er genskabt.

| Dato | Backup | Hvor genskabt | Resultat | Af |
|---|---|---|---|---|
| | | | | |

## Kendte begrænsninger

- Scriptet kopierer ikke backuppen væk fra serveren (se opsætning, trin 4).
- Backup af `.env` og kopien af databaserne er bevidst adskilt og skal begge vedligeholdes.
- Data, der er skrevet efter den seneste backup (op til et døgn), mistes ved en genskabelse, men Monta og MySQL-loggeren fylder det meste på igen. Egne rettelser og eksterne opladninger gør ikke.
- Backuppen er ikke krypteret (den ligger i en mappe kun ejeren kan læse). Gem den eksterne kopi krypteret, hvis den forlader dit eget netværk.
- `/health.php` og `php doctor.php` kontrollerer endnu ikke, at backupjobbet er planlagt eller friskt. Brug `--status`.
