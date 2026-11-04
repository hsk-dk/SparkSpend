<?php
/**
 * The API route table (see includes/Router.php).
 *
 *   'action' => ['handler' => file, 'method' => 'GET'|'POST', 'csrf' => bool]
 *
 * GET  = read endpoint.
 * POST = mutation; csrf => true needs the session's X-CSRF-Token header.
 * The machine endpoint (Home Assistant) authenticates with a Bearer key instead of CSRF.
 */
return [
    // ── Read ────────────────────────────────────────────────────────────────
    'dashboard'            => ['handler' => 'routes/get_dashboard_summary.php',   'method' => 'GET'],
    'charges'              => ['handler' => 'routes/get_charges.php',             'method' => 'GET'],
    'charge-analytics'     => ['handler' => 'routes/get_charge_analytics.php',    'method' => 'GET'],
    'charge-compare'       => ['handler' => 'routes/get_charge_compare.php',      'method' => 'GET'],
    'annual'               => ['handler' => 'routes/get_annual_summary.php',      'method' => 'GET'],
    'anomalies'            => ['handler' => 'routes/get_anomaly_stats.php',       'method' => 'GET'],
    'efficiency'           => ['handler' => 'routes/get_efficiency_stats.php',    'method' => 'GET'],
    'elspot-prices'        => ['handler' => 'routes/get_elspot_prices.php',       'method' => 'GET'],
    'heatpump'             => ['handler' => 'routes/get_heatpump_data.php',       'method' => 'GET'],
    'hourly-consumption'   => ['handler' => 'routes/get_hourly_consumption.php',  'method' => 'GET'],
    'charger-sessions'     => ['handler' => 'routes/get_charger_sessions.php',    'method' => 'GET'],
    'house-power'          => ['handler' => 'routes/get_house_power_data.php',    'method' => 'GET'],
    'monthly-bill'         => ['handler' => 'routes/get_monthly_bill_data.php',   'method' => 'GET'],
    'providers'            => ['handler' => 'routes/get_providers.php',           'method' => 'GET'],
    'provider-stats'       => ['handler' => 'routes/get_provider_stats.php',      'method' => 'GET'],
    'sync-status'          => ['handler' => 'routes/get_sync_status.php',         'method' => 'GET'],
    'system-log'           => ['handler' => 'routes/get_system_log.php',          'method' => 'GET'],
    'vehicle-comparison'   => ['handler' => 'routes/get_vehicle_comparison.php',  'method' => 'GET'],
    'vehicles'             => ['handler' => 'routes/get_vehicles.php',            'method' => 'GET'],
    'weather'              => ['handler' => 'routes/get_weather_data.php',        'method' => 'GET'],

    // ── Mutations (POST + CSRF) ─────────────────────────────────────────────
    'create-ext-charge'      => ['handler' => 'routes/create_ext_charge.php',      'method' => 'POST', 'csrf' => true],
    'create-provider'        => ['handler' => 'routes/create_provider.php',        'method' => 'POST', 'csrf' => true],
    'delete-ext-charge'      => ['handler' => 'routes/delete_ext_charge.php',      'method' => 'POST', 'csrf' => true],
    'delete-provider'        => ['handler' => 'routes/delete_provider.php',        'method' => 'POST', 'csrf' => true],
    'update-ext-charge'      => ['handler' => 'routes/update_ext_charge.php',      'method' => 'POST', 'csrf' => true],
    'update-internal-charge' => ['handler' => 'routes/update_internal_charge.php', 'method' => 'POST', 'csrf' => true],
    'update-provider'        => ['handler' => 'routes/update_provider.php',        'method' => 'POST', 'csrf' => true],
    'update-vehicle'         => ['handler' => 'routes/update_vehicle.php',         'method' => 'POST', 'csrf' => true],
    'trigger-sync'           => ['handler' => 'routes/trigger_sync.php',           'method' => 'POST', 'csrf' => true],

    // ── Machine endpoint (Bearer key, no session/CSRF) ──────────────────────
    'receive-vehicle-data'   => ['handler' => 'routes/receive_vehicle_data.php',   'method' => 'POST'],
];
