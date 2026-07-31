<?php
/**
 * Add performance indexes for common query patterns.
 */
return [
    'description' => 'Add performance indexes to charges and ext_charges',
    'up' => [
        "CREATE INDEX IF NOT EXISTS idx_charges_stopped  ON charges(stoppedAt)",
        "CREATE INDEX IF NOT EXISTS idx_charges_started  ON charges(startedAt)",
        "CREATE INDEX IF NOT EXISTS idx_charges_vehicle  ON charges(vehicleId)",
        "CREATE INDEX IF NOT EXISTS idx_ext_charges_dt   ON ext_charges(datetime)",
    ],
];
