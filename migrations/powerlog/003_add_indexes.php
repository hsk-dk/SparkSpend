<?php
/**
 * Add date indexes for LAG-based delta queries on power log tables.
 */
return [
    'description' => 'Add date indexes for LAG delta queries',
    'up' => [
        "CREATE INDEX IF NOT EXISTS idx_powerlogjord_dt ON powerlogjord(logdate)",
        "CREATE INDEX IF NOT EXISTS idx_powerloghus_dt  ON powerloghus(logdate)",
    ],
];
