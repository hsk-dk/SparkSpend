<?php
/**
 * Annual report: per-year totals for EV (internal + external charges), heat pump and house,
 * with the estimated heat pump / whole-house cost per year (CostEstimates::annual).
 * No HTTP and no cache here (see routes/get_annual_summary.php).
 */
final class AnnualSummary
{
    /**
     * @return array{payload: array, complete: bool}  complete=false when the prices behind the
     *         cost estimate were incomplete (rate-limited): such a payload must not be cached
     */
    public static function build(PDO $chargesDb, PDO $powerlogDb): array
    {
        // =========================================================================
        // EV — internal charges: SQL aggregation by year (using stoppedAt date).
        // For annual totals, the midnight-split precision is unnecessary — at most
        // 1-2 charges per year cross the Dec 31 → Jan 1 boundary.
        // =========================================================================
        $intByYear = [];
        $stmt = $chargesDb->query("
            SELECT strftime('%Y', stoppedAt) AS year,
                   COUNT(*)                   AS cnt,
                   COUNT(DISTINCT strftime('%Y-%m', stoppedAt)) AS months,
                   COALESCE(SUM(consumedKwh), 0) AS kwh,
                   COALESCE(SUM(cost), 0)        AS cost
            FROM charges
            WHERE startedAt IS NOT NULL AND stoppedAt IS NOT NULL
            GROUP BY strftime('%Y', stoppedAt)
            ORDER BY year
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $intByYear[$row['year']] = [
                'cnt'  => intval($row['cnt']),
                'months' => intval($row['months']),
                'kwh'  => round(floatval($row['kwh']), 2),
                'cost' => round(floatval($row['cost']), 2),
            ];
        }
        ksort($intByYear);

        // =========================================================================
        // EV — external charges
        // =========================================================================
        $extByYear = [];
        $extRows = ChargeRepository::getExternal($chargesDb);
        foreach ($extRows as $r) {
            $year = substr($r['datetime'], 0, 4);
            if (!isset($extByYear[$year])) {
                $extByYear[$year] = ['cnt' => 0, 'kwh' => 0.0, 'cost' => 0.0];
            }
            $extByYear[$year]['cnt']++;
            $extByYear[$year]['kwh']  += floatval($r['kwh']);
            $extByYear[$year]['cost'] += floatval($r['pris']);
        }

        // =========================================================================
        // Heatpump — LAG-based daily delta, summed by year
        // =========================================================================
        $allDataStart = '2000-01-01';
        $allDataEnd   = date('Y-m-d');

        $hpByYear = PowerlogRepository::lagDeltaByYear($powerlogDb, 'powerlogjord', $allDataStart, $allDataEnd);

        // =========================================================================
        // House — same pattern, wrapped in try/catch (table may not exist yet)
        // =========================================================================
        $husByYear = null;
        try {
            $husByYear = PowerlogRepository::lagDeltaByYear($powerlogDb, 'powerloghus', $allDataStart, $allDataEnd);
        } catch (\Throwable $husEx) {
            $husByYear = null;
            error_log('getAnnualSummary: house query failed — ' . $husEx->getMessage());
        }

        // =========================================================================
        // Merge all year keys and assemble response
        // =========================================================================
        $allYears = array_unique(array_merge(
            array_keys($intByYear),
            array_keys($extByYear),
            array_keys($hpByYear),
            $husByYear !== null ? array_keys($husByYear) : []
        ));
        sort($allYears);

        $years = [];
        foreach ($allYears as $year) {
            $int = $intByYear[$year] ?? ['cnt' => 0, 'months' => 0, 'kwh' => 0.0, 'cost' => 0.0];
            $ext = $extByYear[$year] ?? ['cnt' => 0, 'kwh' => 0.0, 'cost' => 0.0];
            $hp  = $hpByYear[$year]  ?? 0.0;

            $years[] = [
                'year'     => $year,
                'ev'       => [
                    'charges' => $int['cnt'] + $ext['cnt'],
                    'kwh'     => round($int['kwh'] + $ext['kwh'], 2),
                    'cost'    => round($int['cost'] + $ext['cost'], 2),
                    // Home charging only: what passes the house meter (used for the "rest" split)
                    'home_kwh' => round($int['kwh'], 2),
                    // Months of the year that have at least one home charge (a year with fewer is a partial year)
                    'months'   => $int['months'] ?? 0,
                ],
                'heatpump' => ['kwh' => $hp],
                'hus'      => $husByYear !== null
                    ? ['kwh' => $husByYear[$year] ?? 0.0]
                    : null,
            ];
        }

        // Estimated VP / whole-house cost per year (elvarme model, server-side).
        $_costsComplete = true;
        try {
            $_annual = CostEstimates::annual($years, date('Y-m-d'));
            $_costsComplete = $_annual['complete'];
            foreach ($years as &$_y) {
                $_y['cost'] = $_annual['costs'][(string) $_y['year']] ?? null;
            }
            unset($_y);
        } catch (\Throwable $e) {
            error_log('getAnnualSummary cost estimate: ' . $e->getMessage());
            $_costsComplete = false;
        }

        return ['payload' => ['years' => $years], 'complete' => $_costsComplete];
    }
}
