<?php
/**
 * Repair charges whose vehicleId was stored as a BLOB / TEXT instead of an INTEGER.
 *
 * Found in production: two February 2025 charges (57.8 kWh) had vehicleId stored as the BLOB 0x32
 * ("2"). SQLite compares a BLOB with an INTEGER as different values, so `WHERE vehicleId = 2` and the
 * join to vehicles never matched them: they showed up as belonging to no vehicle and were missing
 * from the per-vehicle totals, efficiency figures and vehicle filters.
 */
return [
    'description' => 'Store charges.vehicleId as INTEGER where it was a BLOB/TEXT',
    'up' => [
        "UPDATE charges
            SET vehicleId = CAST(CAST(vehicleId AS TEXT) AS INTEGER)
          WHERE typeof(vehicleId) IN ('blob', 'text')
            AND CAST(vehicleId AS TEXT) GLOB '[0-9]*'",
    ],
];
