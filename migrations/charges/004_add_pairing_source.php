<?php
/**
 * Track how vehicleId was determined for each charge.
 * Values: 'api' (from Monta), 'matched' (HA time-match), 'manual' (user corrected), 'default' (fallback), 'legacy' (pre-feature)
 */
return [
    'description' => 'Add pairingSource column to track how vehicleId was assigned',
    'up' => [
        "ALTER TABLE charges ADD COLUMN pairingSource TEXT DEFAULT 'legacy'",
    ],
];
