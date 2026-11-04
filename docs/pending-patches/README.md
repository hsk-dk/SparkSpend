# Pending patches

Patches that were written but are **not applied** to the code base. Kept here so the work is
not lost; check whether it is still wanted before applying.

## 0001-Pair-charges-with-the-car-by-the-charger-s-own-sessi.patch

Pairs each Monta charge with the car using the charger's own session data (SoC rise, power
signature) instead of only the cable-plug timing. Adds `includes/ChargerPairing.php`,
`includes/ChargerReconciler.php`, migration `006_fix_blob_vehicle_ids.php`, a real-data
fixture and `dev/test_charger_pairing.php`, and touches `cron/update_monta_data.php`,
`routes/receive_charger_event.php` and `includes/VehiclePairing.php`.

It was generated against an older state of the repository: `git apply --check` already fails
on `routes/receive_charger_event.php` and `CHANGELOG.md`, so it needs a 3-way apply
(`git apply --3way`) and a review of the conflicts.
