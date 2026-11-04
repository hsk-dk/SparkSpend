<?php
/**
 * Charging optimisation: what the home charges cost at the hourly prices and what the same
 * energy would have cost in the cheapest hours the car was available for.
 * The calculation is ChargeOptimization::build() / ChargeOptimizer.
 *
 * GET parameters:
 *   months  (optional, 1-12, default 3) - how far back to look
 */

$_months = max(1, min(12, Http::int($_GET, 'months', 3)));
$_optKey = 'chargeopt_' . $_months . '_' . date('Y-m-d-H') . '_' . Config::evFlexHours() . '_' . Config::evMaxKw();
ApiCache::serve($_optKey, CacheHelper::TTL_LONG);

$_opt = ChargeOptimization::build(DatabaseManager::getChargesDb(), $_months);
ApiCache::respond($_optKey, $_opt['payload'], $_opt['complete']);
