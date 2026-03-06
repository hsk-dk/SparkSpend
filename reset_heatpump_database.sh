#!/bin/bash
###############################################################################
# SparkSpend Heat Pump Database Initialization Script
#
# Creates a fresh SQLite database for heat pump data and initializes tables
# Run this once: bash reset_heatpump_database.sh
###############################################################################

APP_ROOT="/var/www/monta"
POWERLOG_DB="$APP_ROOT/data/powerlog_data.db"
DATA_DIR="$APP_ROOT/data"

echo "=========================================="
echo "Resetting Heat Pump Database"
echo "=========================================="
echo ""

# Step 1: Ensure data directory exists
echo "Step 1: Checking data directory..."
if [ ! -d "$DATA_DIR" ]; then
    echo "Creating data directory: $DATA_DIR"
    mkdir -p "$DATA_DIR"
fi
echo "✓ Data directory ready"
echo ""

# Step 2: Backup existing database if it exists
if [ -f "$POWERLOG_DB" ]; then
    BACKUP_FILE="$POWERLOG_DB.backup.$(date +%s)"
    echo "Step 2: Backing up existing database..."
    cp "$POWERLOG_DB" "$BACKUP_FILE"
    echo "✓ Backup created: $BACKUP_FILE"
    echo ""

    # Step 3: Delete the old database
    echo "Step 3: Deleting old database..."
    rm "$POWERLOG_DB"
    echo "✓ Old database removed"
else
    echo "Step 2: No existing database found (skip backup)"
fi
echo ""

# Step 4: Create fresh empty SQLite database
echo "Step 4: Creating fresh SQLite database..."
sqlite3 "$POWERLOG_DB" <<EOF
-- Create powerlogjord table to match existing schema
CREATE TABLE IF NOT EXISTS powerlogjord (
    logdate TEXT NOT NULL,
    kwh REAL NOT NULL,
    PRIMARY KEY (logdate)
);

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_powerlogjord_logdate ON powerlogjord(logdate);
EOF

if [ $? -eq 0 ]; then
    echo "✓ Database created successfully"
else
    echo "✗ Failed to create database"
    exit 1
fi
echo ""

# Step 5: Verify database structure
echo "Step 5: Verifying database structure..."
sqlite3 "$POWERLOG_DB" ".tables"
echo "✓ Tables verified"
echo ""

# Step 6: Check database file
echo "Step 6: Checking database file..."
ls -lh "$POWERLOG_DB"
echo "✓ Database file ready"
echo ""

# Step 7: Initialize sync tracking via PHP setup script
echo "Step 7: Initializing sync tracking table..."
cd "$APP_ROOT"
php cron/setup_heatpump_sync.php

if [ $? -eq 0 ]; then
    echo "✓ Sync tracking initialized"
else
    echo "✗ Failed to initialize sync tracking"
    exit 1
fi
echo ""

echo "=========================================="
echo "Database Reset Complete!"
echo "=========================================="
echo ""
echo "Next steps:"
echo "1. Run manual sync test:"
echo "   php /var/www/monta/cron/sync_heatpump_data.php"
echo ""
echo "2. Verify data was synced:"
echo "   sqlite3 /var/www/monta/data/powerlog_data.db \"SELECT COUNT(*) FROM powerlogjord;\""
echo ""
echo "3. Check sync status:"
echo "   sqlite3 /var/www/monta/data/powerlog_data.db \"SELECT * FROM sync_log;\""
echo ""
