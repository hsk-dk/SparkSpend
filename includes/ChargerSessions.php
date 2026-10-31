<?php
/**
 * Assemble charging sessions from charger events (pure function, unit-tested:
 *   php dev/test_charger_events.php).
 *
 * A session runs from the car being plugged in to it being unplugged. The cable
 * goes in hours before charging starts when Monta schedules the charge for the
 * cheap night hours (observed: 4-5.4 h), so the two moments are kept apart:
 *
 *   plugged -> charge_start -> charge_end -> unplugged
 *
 * charge_end carries the session's FINAL energy (the charger's session counter,
 * read just before it resets to 0). A session can have several start/end pairs
 * (paused and resumed); their energies and durations add up.
 */
final class ChargerSessions {

    private const ORDER = ['plugged' => 0, 'charge_start' => 1, 'charge_end' => 2, 'unplugged' => 3];

    /**
     * @param array<int,array> $events rows with eventAt, event, sessionEnergy, lifetimeEnergy, power,
     *                                 chargerStatus and cars (decoded array or null)
     * @return array<int,array> sessions, oldest first
     */
    public static function build(array $events): array {
        usort($events, static fn($a, $b) =>
            [strtotime($a['eventAt']), self::ORDER[$a['event']] ?? 9] <=> [strtotime($b['eventAt']), self::ORDER[$b['event']] ?? 9]);

        $sessions = [];
        $cur = null;
        $segStart = null;

        $close = static function () use (&$sessions, &$cur, &$segStart): void {
            if ($cur === null) return;
            $prev = $sessions ? end($sessions)['energyKwh'] : null;
            $sessions[] = self::finish($cur, $prev);
            $cur = null; $segStart = null;
        };
        $open = static fn() => [
            'pluggedAt' => null, 'chargeStartAt' => null, 'chargeEndAt' => null, 'unpluggedAt' => null,
            'segments' => 0, 'energyKwh' => 0.0, 'chargingSec' => 0, 'energySeen' => false,
            'fallbackEnergy' => null, 'lifetimeStart' => null, 'lifetimeEnd' => null,
            'cars' => ['plugged' => null, 'charge_start' => null, 'charge_end' => null, 'unplugged' => null],
            'ended' => false,
        ];

        foreach ($events as $e) {
            $type = $e['event'];
            $at   = $e['eventAt'];
            $snap = $e['cars'] ?? null;

            if ($type === 'plugged') {
                $close();                           // a new plug-in while one is open: the old one lost its 'unplugged'
                $cur = $open();
                $cur['pluggedAt'] = $at;
                $cur['cars']['plugged'] = $snap;
                continue;
            }
            if ($cur === null || ($type === 'charge_start' && $cur['ended'])) {
                $close();
                $cur = $open();                     // first events seen mid-session, or charging resumed after unplug
            }

            switch ($type) {
                case 'charge_start':
                    $cur['chargeStartAt'] ??= $at;
                    $cur['segments']++;
                    $segStart = strtotime($at);
                    $cur['cars']['charge_start'] ??= $snap;
                    if ($cur['lifetimeStart'] === null) $cur['lifetimeStart'] = $e['lifetimeEnergy'] ?? null;
                    break;
                case 'charge_end':
                    $cur['chargeEndAt'] = $at;
                    if ($segStart !== null) { $cur['chargingSec'] += max(0, strtotime($at) - $segStart); $segStart = null; }
                    if (isset($e['sessionEnergy'])) { $cur['energyKwh'] += (float) $e['sessionEnergy']; $cur['energySeen'] = true; }
                    $cur['cars']['charge_end'] = $snap;
                    $cur['lifetimeEnd'] = $e['lifetimeEnergy'] ?? $cur['lifetimeEnd'];
                    break;
                case 'unplugged':
                    $cur['unpluggedAt'] = $at;
                    $cur['cars']['unplugged'] = $snap;
                    // Last resort when the reset was missed: whatever the counter showed on unplug.
                    if (isset($e['sessionEnergy']) && (float) $e['sessionEnergy'] > 0) $cur['fallbackEnergy'] = (float) $e['sessionEnergy'];
                    $cur['ended'] = true;
                    $close();
                    break;
            }
        }
        if ($cur !== null) {
            $s = self::finish($cur, $sessions ? end($sessions)['energyKwh'] : null);
            $s['open'] = true;
            $sessions[] = $s;
        }
        return $sessions;
    }

    private static function finish(array $c, ?float $previousEnergy): array {
        // After an integration reload the charger's session counter shows the PREVIOUS
        // session's final value instead of 0 (seen in the real data). So the on-unplug
        // reading is only trusted when charging actually happened in this session and
        // the value is not simply the last session's energy repeated.
        $fallback = $c['fallbackEnergy'];
        if ($fallback !== null && ($c['segments'] === 0 || ($previousEnergy !== null && abs($fallback - $previousEnergy) < 0.0015))) {
            $fallback = null;
        }
        $energy = $c['energySeen'] ? $c['energyKwh'] : $fallback;
        $hours  = $c['chargingSec'] > 0 ? $c['chargingSec'] / 3600 : null;
        return [
            'pluggedAt'     => $c['pluggedAt'],
            'chargeStartAt' => $c['chargeStartAt'],
            'chargeEndAt'   => $c['chargeEndAt'],
            'unpluggedAt'   => $c['unpluggedAt'],
            'energyKwh'     => $energy !== null ? round($energy, 3) : null,
            'chargingHours' => $hours !== null ? round($hours, 3) : null,
            'avgPowerKw'    => ($energy !== null && $hours !== null) ? round($energy / $hours, 2) : null,
            'segments'      => $c['segments'],
            'cars'          => $c['cars'],
            'open'          => false,
        ];
    }
}
