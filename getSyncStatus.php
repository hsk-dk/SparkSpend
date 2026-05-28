<?php
/**
 * Sync Status API
 *
 * Returns the last sync time and status for each data source:
 *   - monta     : MAX(stoppedAt) from charges table
 *   - heatpump  : sync_log row (source = 'heatpump') in powerlog_data.db
 *   - hus       : sync_log row (source = 'housepowerlog') in powerlog_data.db
 *
 * Response shape:
 * {
 *   "sources": [
 *     {
 *       "id":             "monta",
 *       "label":          "El-bil (Monta)",
 *       "last_sync":      "2026-03-16 18:30:00" | null,
 *       "status":         "ok" | "error" | "unknown",
 *       "records_synced": int | null
 *     }, ...
 *   ]
 * }
 */

require 'includes/configuration.php';
require 'includes/DatabaseManager.php';
require 'includes/QueryBuilder.php';

header('Content-Type: application/json');

// ─── 30-second cache ──────────────────────────────────────────────────────────
$_cached = QueryBuilder::fileCacheRead('syncstatus', 30);
if ($_cached !== null) { echo $_cached; exit; }
// ─────────────────────────────────────────────────────────────────────────────

$sources = [];

// ── Monta (EV) ────────────────────────────────────────────────────────────────
try {
    $chargesDb = DatabaseManager::getChargesDb();

    // Read from sync_log written by update_monta_data.php on each run.
    // Falls back to unknown if the table doesn't exist yet (first run before upgrade).
    $row = false;
    try {
        $stmt = $chargesDb->query(
            "SELECT last_sync_timestamp, last_sync_count, error_message, updated_at
             FROM sync_log WHERE source = 'monta'"
        );
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    } catch (Exception $inner) {
        // Table not yet created — will be created on next cron run
    }

    if ($row) {
        $hasError  = !empty($row['error_message']) && empty($row['last_sync_timestamp']);
        $sources[] = [
            'id'             => 'monta',
            'label'          => 'El-bil (Monta)',
            'last_sync'      => $row['last_sync_timestamp'] ?? $row['updated_at'] ?? null,
            'status'         => $hasError ? 'error' : 'ok',
            'records_synced' => isset($row['last_sync_count']) ? intval($row['last_sync_count']) : null,
        ];
    } else {
        // sync_log not yet populated — fall back to last charge session time
        $fallback = $chargesDb->query("SELECT MAX(stoppedAt) AS last FROM charges")
                              ->fetch(PDO::FETCH_ASSOC);
        $last = $fallback['last'] ?? null;
        if ($last) $last = date('Y-m-d H:i:s', strtotime($last));
        $sources[] = [
            'id'             => 'monta',
            'label'          => 'El-bil (Monta)',
            'last_sync'      => $last,
            'status'         => 'unknown',
            'records_synced' => null,
        ];
    }
} catch (\Throwable $e) {
    $sources[] = [
        'id'             => 'monta',
        'label'          => 'El-bil (Monta)',
        'last_sync'      => null,
        'status'         => 'error',
        'records_synced' => null,
    ];
    error_log('getSyncStatus monta: ' . $e->getMessage());
}

// ── Heatpump + House (sync_log in powerlog_data.db) ──────────────────────────
$syncSources = [
    ['heatpump', 'Jordvarme',    'heatpump'],
    ['hus',      'Hus',          'housepowerlog'],
];

try {
    $powerlogDb = DatabaseManager::getPowerlogDb();

    foreach ($syncSources as [$id, $label, $logSource]) {
        try {
            $stmt = $powerlogDb->prepare(
                "SELECT last_sync_timestamp, last_sync_count, error_message, updated_at
                 FROM sync_log WHERE source = ?"
            );
            $stmt->execute([$logSource]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                // Error status: error_message set AND no successful last_sync_timestamp
                $hasError = !empty($row['error_message']) && empty($row['last_sync_timestamp']);
                $sources[] = [
                    'id'             => $id,
                    'label'          => $label,
                    'last_sync'      => $row['updated_at'] ?? $row['last_sync_timestamp'] ?? null,
                    'status'         => $hasError ? 'error' : 'ok',
                    'records_synced' => isset($row['last_sync_count']) ? intval($row['last_sync_count']) : null,
                ];
            } else {
                $sources[] = [
                    'id'             => $id,
                    'label'          => $label,
                    'last_sync'      => null,
                    'status'         => 'unknown',
                    'records_synced' => null,
                ];
            }
        } catch (Exception $inner) {
            $sources[] = [
                'id'             => $id,
                'label'          => $label,
                'last_sync'      => null,
                'status'         => 'unknown',
                'records_synced' => null,
            ];
        }
    }
} catch (\Throwable $e) {
    foreach ($syncSources as [$id, $label]) {
        $sources[] = [
            'id'             => $id,
            'label'          => $label,
            'last_sync'      => null,
            'status'         => 'error',
            'records_synced' => null,
        ];
    }
    error_log('getSyncStatus powerlog: ' . $e->getMessage());
}

$response = json_encode(['sources' => $sources]);
QueryBuilder::fileCacheWrite('syncstatus', $response);
echo $response;
?>
