<?php
/**
 * QueryBuilder — Backwards-Compatible Facade
 *
 * This class delegates to domain-specific repository classes.
 * Existing callers (endpoints, cron scripts) continue working without changes.
 * New code should call the domain classes directly:
 *
 *   VehicleRepository, ProviderRepository, ChargeRepository,
 *   PowerlogRepository, CacheHelper, DateHelper
 */

require_once __DIR__ . '/VehicleRepository.php';
require_once __DIR__ . '/ProviderRepository.php';
require_once __DIR__ . '/ChargeRepository.php';
require_once __DIR__ . '/PowerlogRepository.php';
require_once __DIR__ . '/CacheHelper.php';
require_once __DIR__ . '/DateHelper.php';

class QueryBuilder {

    // =========================================================================
    // Vehicle delegates
    // =========================================================================

    public static function selectAllVehicles(PDO $db): array {
        return VehicleRepository::getAll($db);
    }

    public static function selectVehicleById(PDO $db, int $id): ?array {
        return VehicleRepository::getById($db, $id);
    }

    public static function updateVehicle(PDO $db, int $id, string $name): bool {
        return VehicleRepository::update($db, $id, $name);
    }

    public static function upsertVehicle(PDO $db, int $id, string $name = ''): void {
        VehicleRepository::upsert($db, $id, $name);
    }

    public static function insertVehicleData(PDO $db, array $data): bool {
        return VehicleRepository::insertOdometerReading($db, $data);
    }

    // =========================================================================
    // Provider delegates
    // =========================================================================

    public static function selectAllProviders(PDO $db): array {
        return ProviderRepository::getAll($db);
    }

    public static function insertProvider(PDO $db, string $name): int {
        return ProviderRepository::insert($db, $name);
    }

    public static function updateProvider(PDO $db, int $id, string $name): bool {
        return ProviderRepository::update($db, $id, $name);
    }

    public static function deleteProvider(PDO $db, int $id): bool {
        return ProviderRepository::delete($db, $id);
    }

    public static function providerUsageCount(PDO $db, int $id): int {
        return ProviderRepository::usageCount($db, $id);
    }

    // =========================================================================
    // Charge delegates
    // =========================================================================

    public static function updateInternalCharge(PDO $db, array $data): bool {
        return ChargeRepository::updateInternal($db, $data);
    }

    public static function updateExternalCharge(PDO $db, array $data): bool {
        return ChargeRepository::updateExternal($db, $data);
    }

    public static function insertExternalCharge(PDO $db, array $data): array {
        return ChargeRepository::insertExternal($db, $data);
    }

    public static function deleteExternalCharge(PDO $db, int $id): bool {
        return ChargeRepository::deleteExternal($db, $id);
    }

    public static function getExternalCharges(
        PDO $db,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?int $vehicleId = null,
        bool $includeZeroKwh = true
    ): array {
        return ChargeRepository::getExternal($db, $fromDate, $toDate, $vehicleId, $includeZeroKwh);
    }

    public static function getCostTrend(PDO $db, array $filters): array {
        return ChargeRepository::getCostTrend($db, $filters);
    }

    public static function getCostStatistics(PDO $db, array $filters): array {
        return ChargeRepository::getCostStatistics($db, $filters);
    }

    public static function getVehicleCostComparison(PDO $db, array $filters): array {
        return ChargeRepository::getVehicleCostComparison($db, $filters);
    }

    // =========================================================================
    // Date utility delegates
    // =========================================================================

    public static function parseDateRange(string $dateRange): array {
        return DateHelper::parseDateRange($dateRange);
    }

    public static function splitChargeByDays(
        string $startedAt,
        string $stoppedAt,
        float  $totalKwh,
        float  $totalCost
    ): array {
        return DateHelper::splitChargeByDays($startedAt, $stoppedAt, $totalKwh, $totalCost);
    }

    // =========================================================================
    // Powerlog delegates
    // =========================================================================

    public static function lagDelta(PDO $db, string $table, string $from, string $to): array {
        return PowerlogRepository::lagDelta($db, $table, $from, $to);
    }

    public static function lagDeltaByMonth(PDO $db, string $table, string $from, string $to): array {
        return PowerlogRepository::lagDeltaByMonth($db, $table, $from, $to);
    }

    public static function lagDeltaByYear(PDO $db, string $table, string $from, string $to): array {
        return PowerlogRepository::lagDeltaByYear($db, $table, $from, $to);
    }

    // =========================================================================
    // Cache delegates
    // =========================================================================

    public static function fileCacheRead(string $key, int $ttl): ?string {
        return CacheHelper::read($key, $ttl);
    }

    public static function fileCacheWrite(string $key, string $json): void {
        CacheHelper::write($key, $json);
    }

    public static function fileCacheInvalidatePattern(string $prefix): void {
        CacheHelper::invalidatePattern($prefix);
    }
}
