# Golden master: electricity-cost figures (MOCK_EDS test instance)

Numbers shown by the UI on the seeded test instance (`dev/seed_dev.php`, `MOCK_EDS=true`,
seed 42, captured 2026-11-04) before the cost model moved from JavaScript to PHP.
Used to prove that the refactor did not change a single figure. Meter data is relative to
"today", so the numbers are only comparable on the day of capture (re-seed + re-capture
otherwise).

## Dashboard (Oversigt)
- Forventet elregning ~1344 kr; EV ~651, VP ~486, Rest ~102, Abonnement ~105; hidtil ~156 kr
- Jordvarme card ~61 kr; Hus card ~32 kr; EV spotpris tooltip 1.17 kr/kWh

## Årsrapport (kr)
| År | VP kr | Hus total kr |
|---|---|---|
| 2026 | 3.318 | 9.635 |
| 2025 | 7.636 | 15.618 |
| 2024 | 1.949 | 3.540 |

## Hus → Forbrug
- daily (Nov 2026): total 140,06; VP 60,30; EV 37,03; Rest 42,73
- monthly (2026): total 9.788,57; VP 3.348,39; EV 5.856,76; Rest 583,42
- compare: 2026 9.537,59; 2025 15.627,84; 2024 2.823,12

## Hus → Regning (monthly VP kr / Rest kr)
Nov 2026 59/32, Okt 388/40, Sep 223/57, Aug 82/0, Jul 35/50, Jun 83/57, Maj 224/0, Apr 342/12,
Mar 498/146, Feb 643/106, Jan 769/82, Dec 2025 975/63 ... totals VP 11074, Rest 1939.

## Hus → Fordeling
El-bil 11.353 kr (70 %), Varmepumpe 4.322 kr (26 %), Restforbrug 646 kr (4 %)

## Elspot API hashes (sha256[:16] of the JSON body)
```
2026-11-01|2026-11-04|hourly|high  c7dd401bdc356a75
2026-11-01|2026-11-04|hourly|low   5d67470e7b1a6f5e
2024-12-01|2026-11-04|daily|high   3c0250227966d479
2024-12-01|2026-11-04|daily|low    31dce48f83a9f472
2025-09-25|2025-10-05|daily|low    ed0a1ec2e2fe7fe1
2025-09-25|2025-10-05|hourly|high  ddda496b3f9c8e4e
2026-10-25|2026-10-27|hourly|low   a2d8872ac89c9bcd
```

Known pre-existing issues noticed while capturing: Hus "År til dato" shows `NaN kWh`;
a direct link to `#aarsrapport` / `#hus/...` can leave "Indlæser…" in a hidden browser pane
(initialisation waits for `requestAnimationFrame`).
