# SparkSpend Heat Pump MySQL Integration - Phase 5 Testing Guide

## Connection Test Results ✅

**Status:** VERIFIED WORKING

```
MySQL Server: 10.0.1.7:3306
Database: playground
Table: powerlogjord
Columns: logdate (datetime), watt (float), kwh (float)
Sample Data: Last reading 2026-03-04 18:15:00 - 42W, 10583 kWh
```

The MySQL connection is confirmed working and data is accessible.

---

## Phase 5 Testing - Remaining Steps

### Prerequisites
- SSH access to your server
- Working directory: `/var/www/monta`
- SQLite installed: `sqlite3`

### Step 1: Initialize Sync Tracking Table

**Purpose:** Create the `sync_log` table in SQLite to track sync history

**Command:**
```bash
cd /var/www/monta
php cron/setup_heatpump_sync.php
```

**Expected Output:**
```
Heat pump sync tracking initialized successfully.
sync_log table created in /var/www/monta/data/powerlog_data.db
```

**Verify:**
```bash
sqlite3 /var/www/monta/data/powerlog_data.db "SELECT * FROM sync_log WHERE source='heatpump';"
```

---

### Step 2: Manual Sync Test

**Purpose:** Test the sync script manually to verify data transfer

**Command:**
```bash
cd /var/www/monta
php cron/sync_heatpump_data.php
```

**Expected Output:**
Script runs silently if successful. Check the log file for details.

**Check Sync Log:**
```bash
tail -30 cron/cron.log
```

**Expected Log Output:**
```
[2026-03-04 18:30:15] [INFO] Heat Pump Sync: Starting heat pump data sync...
[2026-03-04 18:30:15] [INFO] Heat Pump Sync: Last sync: Never
[2026-03-04 18:30:16] [INFO] Heat Pump Sync: Found 150 new records to sync
[2026-03-04 18:30:17] [INFO] Heat Pump Sync: Successfully synced 150 records
[2026-03-04 18:30:17] [INFO] Heat Pump Sync: Heat pump data sync completed successfully
```

**Troubleshooting:**
- If you see "Sync already in progress", the sync is taking too long - wait and retry
- If you see MySQL error, check credentials in `.env` file
- If you see "No new records to sync", that's OK - means no new data since last sync

---

### Step 3: Verify SQLite Database Updated

**Purpose:** Confirm data was transferred from MySQL to SQLite

**Count Records:**
```bash
sqlite3 /var/www/monta/data/powerlog_data.db "SELECT COUNT(*) FROM powerlogjord;"
```

Expected: Should see a number (how many records total in the database)

**View Sample Records:**
```bash
sqlite3 /var/www/monta/data/powerlog_data.db <<EOF
SELECT logdate, kwh FROM powerlogjord ORDER BY logdate DESC LIMIT 5;
EOF
```

**Check Sync Metadata:**
```bash
sqlite3 /var/www/monta/data/powerlog_data.db <<EOF
SELECT source, last_sync_timestamp, last_sync_count, updated_at FROM sync_log WHERE source='heatpump';
EOF
```

Expected: Should show the timestamp when sync last ran and how many records were synced

---

### Step 4: Register Cron Job

**Purpose:** Schedule the sync to run automatically every 5 minutes

**Edit Crontab:**
```bash
crontab -e
```

**Add This Line:**
```
*/5 * * * * php /var/www/monta/cron/sync_heatpump_data.php >> /var/log/heatpump_sync.log 2>&1
```

**Verify Cron Job Added:**
```bash
crontab -l | grep sync_heatpump_data.php
```

Expected: Should show the line you just added

**Cron Syntax Explanation:**
```
*/5    *    *    *    *    /path/to/script
│      │    │    │    │    │
│      │    │    │    │    └─ Command to run
│      │    │    │    └────── Day of week (0-6, 0=Sunday)
│      │    │    └─────────── Month (1-12)
│      │    └──────────────── Day of month (1-31)
│      └───────────────────── Hour (0-23)
└──────────────────────────── Minute (*/5 = every 5 minutes)
```

---

### Step 5: Monitor Sync Execution

**Wait 5 minutes** for the cron job to run, then check:

**View Sync Log:**
```bash
tail -f /var/log/heatpump_sync.log
```

This will show a live stream of sync logs as they're written. Press Ctrl+C to exit.

**View Last 20 Lines:**
```bash
tail -20 /var/log/heatpump_sync.log
```

**View All Syncs Today:**
```bash
grep "2026-03-04" /var/log/heatpump_sync.log
```

**Check for Errors:**
```bash
grep ERROR /var/log/heatpump_sync.log
grep WARN /var/log/heatpump_sync.log
```

---

### Step 6: Verify Dashboard

**Access the Dashboard:**
1. Open SparkSpend in your browser
2. Navigate to the "Settings" tab or "Heat Pump Data" section
3. Verify the chart displays data from MySQL

**Expected Behavior:**
- Chart should show heat pump power consumption over time
- Data should update after each cron cycle (every 5 minutes)
- If you had manually dumped data before, it should now be supplemented with new MySQL data

---

## Troubleshooting

### Cron Job Not Running
**Check if cron daemon is running:**
```bash
sudo systemctl status cron
```

**Check system cron logs:**
```bash
sudo tail -20 /var/log/syslog | grep CRON
```

**Check file permissions:**
```bash
ls -la /var/www/monta/cron/sync_heatpump_data.php
# Should be readable by the cron user (usually www-data or root)
```

### Sync Lock File Stuck
**If sync fails with "already in progress" but no sync is running:**
```bash
rm /var/www/monta/cron/sync_heatpump.lock
```

Then retry the sync manually.

### MySQL Connection Issues
**Test MySQL connectivity directly:**
```bash
mysql -h 10.0.1.7 -u power -p -D playground -e "SELECT COUNT(*) FROM powerlogjord;"
# When prompted, enter password: power1234
```

**From PHP (create quick test):**
```php
<?php
$db = new PDO('mysql:host=10.0.1.7;dbname=playground', 'power', 'power1234');
echo $db->query('SELECT COUNT(*) FROM powerlogjord')->fetchColumn();
?>
```

### SQLite Database Not Updating
**Check SQLite file permissions:**
```bash
ls -la /var/www/monta/data/powerlog_data.db
# Must be writable by web server user
chmod 664 /var/www/monta/data/powerlog_data.db
```

**Verify powerlogjord table exists:**
```bash
sqlite3 /var/www/monta/data/powerlog_data.db ".tables"
```

---

## Files Reference

| File | Purpose |
|------|---------|
| `includes/MySQLManager.php` | MySQL connection manager (singleton pattern) |
| `includes/configuration.php` | Loads MySQL config from .env |
| `cron/sync_heatpump_data.php` | Main sync script - run every 5 minutes |
| `cron/setup_heatpump_sync.php` | One-time initialization - creates sync_log table |
| `test_mysql_connection.php` | Connection test utility |
| `test_heatpump_integration.sh` | Complete testing script (bash) |
| `.env` | Configuration file with MySQL credentials |

---

## Architecture Overview

```
MySQL Server (10.0.1.7)
    ↓ (powerlogjord table)
    ↓
MySQLManager::getHeatpumpDb()
    ↓ (connects every 5 minutes)
    ↓
sync_heatpump_data.php (cron job)
    ↓ (fetches new/updated records)
    ↓
SQLite: powerlog_data.db
    ↓ (powerlogjord table)
    ↓
getHeatpumpData.php (API endpoint)
    ↓
SparkSpend Dashboard
    ↓
Heat Pump Data Charts
```

---

## Timeline

1. **Manual Test** (now if running setup/manual sync):
   - Initialize tracking table: ~5 seconds
   - Run manual sync: 5-30 seconds (depends on record count)
   - Verify in dashboard: immediate

2. **Automated Cron** (after setup):
   - Register cron job: immediate
   - First automatic sync: within 5 minutes
   - Subsequent syncs: every 5 minutes

---

## Success Criteria

✅ All tests passed if:
- MySQL connection successful
- `sync_log` table created in SQLite
- Manual sync completes without errors
- `powerlog_data.db` contains records with logdate and kwh
- Cron job registered and shows in `crontab -l`
- Dashboard displays heat pump data

---

## Next Steps

After completing all tests:
1. Monitor the sync logs for 24 hours to verify stability
2. Check `cron.log` and `/var/log/heatpump_sync.log` for any warnings
3. Verify data accuracy by comparing MySQL and SQLite
4. Once stable, consider running analytics on the heat pump data

---
