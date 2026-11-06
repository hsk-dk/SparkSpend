<?php
/**
 * Monthly bill data: EV charging, heat pump and house consumption per month for the last N
 * months, with the heat pump / rest cost per month (CostEstimates::monthly). No HTTP and no
 * cache here (see routes/get_monthly_bill_data.php).
 */
final class MonthlyBill
{
    /**
     * @return array{payload: array, complete: bool}  complete=false when prices were incomplete
     */
    public static function build(PDO $chargesDb, PDO $powerlogDb, int $numMonths): array
    {
        // Build list of YYYY-MM strings from current month back N months.
        $months = [];
        $baseDate = date('Y-m') . '-01';
        for ($i = 0; $i < $numMonths; $i++) {
            $months[] = date('Y-m', strtotime("-{$i} months", strtotime($baseDate)));
        }
        $oldest      = end($months);
        $rangeStart  = $oldest . '-01';
        $rangeEnd    = date('Y-m-t');

        // =========================================================================
        // EV — internal charges: raw fetch, proportional midnight-split by month
        // =========================================================================
        $stmtInt = $chargesDb->prepare("
            SELECT startedAt, stoppedAt,
                   COALESCE(consumedKwh, 0) AS kwh,
                   COALESCE(cost, 0) AS cost
            FROM charges
            WHERE DATE(startedAt) <= ? AND DATE(stoppedAt) >= ?
        ");
        $stmtInt->execute([$rangeEnd, $rangeStart]);
        $internalByMonth = [];
        foreach ($stmtInt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $splits = DateHelper::splitChargeByDays(
                $row['startedAt'], $row['stoppedAt'],
                floatval($row['kwh']), floatval($row['cost'])
            );
            foreach ($splits as $day => $slice) {
                $month = substr($day, 0, 7);
                if ($month < $oldest) continue;
                if (!isset($internalByMonth[$month])) {
                    $internalByMonth[$month] = ['kwh' => 0.0, 'cost' => 0.0];
                }
                $internalByMonth[$month]['kwh']  += $slice['kwh'];
                $internalByMonth[$month]['cost'] += $slice['cost'];
            }
        }

        // =========================================================================
        // HP — monthly consumption from powerlogjord
        // =========================================================================
        $hpDeltaByMonth = PowerlogRepository::lagDeltaByMonth($powerlogDb, 'powerlogjord', $rangeStart, $rangeEnd);
        $hpByMonth = [];
        foreach ($hpDeltaByMonth as $month => $kwh) {
            $hpByMonth[$month] = ['kwh' => $kwh];
        }

        // =========================================================================
        // House — whole-house meter from SQLite powerloghus
        // =========================================================================
        $husByMonth = [];
        try {
            $husDeltaByMonth = PowerlogRepository::lagDeltaByMonth($powerlogDb, 'powerloghus', $rangeStart, $rangeEnd);
            foreach ($husDeltaByMonth as $month => $kwh) {
                $husByMonth[$month] = ['kwh' => $kwh];
            }
        } catch (Exception $husEx) {
            error_log('getMonthlyBillData: house power query failed — ' . $husEx->getMessage());
        }

        // =========================================================================
        // EV per month = HOME charging only. External charges (away from home) never pass the
        // house meter and are not on the electricity bill, so they are left out of this split.
        // =========================================================================
        $evByMonth = [];
        foreach ($internalByMonth as $m => $int) {
            $evByMonth[$m] = ['kwh' => round($int['kwh'], 2), 'cost' => round($int['cost'], 2)];
        }

        // Heat pump / rest cost per month (elvarme model, server-side). null = no prices.
        $_billCost = null; $_billComplete = true;
        try {
            $_m = CostEstimates::monthly($months, $hpByMonth, $husByMonth, $evByMonth, $rangeStart, $rangeEnd);
            if ($_m !== null) { $_billCost = $_m['costs']; $_billComplete = $_m['complete']; }
        } catch (\Throwable $e) {
            error_log('getMonthlyBillData cost estimate: ' . $e->getMessage());
            $_billComplete = false;
        }

        return [
            'payload' => [
                'months' => $months,
                'ev'     => $evByMonth,
                'hp'     => $hpByMonth,
                'hus'    => $husByMonth,
                'cost'   => $_billCost,
                'range'  => ['start' => $rangeStart, 'end' => $rangeEnd],
            ],
            'complete' => $_billComplete,
        ];
    }
}
