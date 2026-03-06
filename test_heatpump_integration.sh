#!/bin/bash
###############################################################################
# SparkSpend Heat Pump MySQL Integration - Complete Testing Script
#
# This script performs all Phase 5 testing steps for the heat pump sync
# Run this on your server: bash test_heatpump_integration.sh
###############################################################################

APP_ROOT="/var/www/monta"
POWERLOG_DB="$APP_ROOT/data/powerlog_data.db"
CRON_LOG="$APP_ROOT/cron/cron.log"
HEATPUMP_SYNC_LOG="/var/log/heatpump_sync.log"

echo "=========================================="
echo "SparkSpend Heat Pump Integration Testing"
echo "=========================================="
echo ""

# Step 1: Initialize sync tracking table
echo "STEP 1: Initializing sync tracking table..."
echo "Command: php $APP_ROOT/cron/setup_heatpump_sync.php"
php "$APP_ROOT/cron/setup_heatpump_sync.php"
if [ $? -eq 0 ]; then
    echo "✓ Sync tracking table initialized"
else
    echo "✗ Failed to initialize sync tracking"
    exit 1
fi
echo ""

# Verify sync_log table exists
echo "Verifying sync_log table in SQLite..."
sqlite3 "$POWERLOG_DB" "SELECT * FROM sync_log WHERE source='heatpump';"
echo ""

# Step 2: Manual sync test
echo "STEP 2: Running manual sync test..."
echo "Command: php $APP_ROOT/cron/sync_heatpump_data.php"
php "$APP_ROOT/cron/sync_heatpump_data.php"
if [ $? -eq 0 ]; then
    echo "✓ Manual sync completed"
else
    echo "✗ Sync failed"
    exit 1
fi
echo ""

# Check sync log
echo "Sync log output:"
echo "---"
tail -20 "$CRON_LOG"
echo "---"
echo ""

# Step 3: Verify SQLite data
echo "STEP 3: Verifying powerlog_data.db..."
RECORD_COUNT=$(sqlite3 "$POWERLOG_DB" "SELECT COUNT(*) FROM powerlogjord;")
echo "Total records in powerlogjord: $RECORD_COUNT"

echo ""
echo "Last 5 records synced:"
sqlite3 "$POWERLOG_DB" "SELECT logdate, kwh FROM powerlogjord ORDER BY logdate DESC LIMIT 5;"
echo ""

# Check sync_log entry
echo "Last sync status:"
sqlite3 "$POWERLOG_DB" "SELECT source, last_sync_timestamp, last_sync_count FROM sync_log WHERE source='heatpump';"
echo ""

# Step 4: Cron job registration
echo "STEP 4: Cron job registration instructions"
echo "---"
echo "Add this line to your crontab (crontab -e):"
echo "*/5 * * * * php $APP_ROOT/cron/sync_heatpump_data.php >> $HEATPUMP_SYNC_LOG 2>&1"
echo ""
echo "To verify crontab was updated, run:"
echo "crontab -l | grep sync_heatpump_data.php"
echo "---"
echo ""

echo "=========================================="
echo "Testing Complete!"
echo "=========================================="
echo ""
echo "Next steps:"
echo "1. Add the cron job to your system crontab"
echo "2. Wait 5 minutes for the next sync to run"
echo "3. Check the dashboard Heat Pump Data chart"
echo "4. Monitor the sync logs at: $HEATPUMP_SYNC_LOG"
echo ""
