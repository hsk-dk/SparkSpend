"""
Debug script for dashboard comparison calculations.
Run: python debug_dashboard.py
Requires: data/charging_data.db and data/powerlog_data.db
"""
import sqlite3
import os
import calendar
from datetime import date, datetime, timedelta

CHARGES_DB  = os.path.join(os.path.dirname(__file__), "data", "charging_data.db")
POWERLOG_DB = os.path.join(os.path.dirname(__file__), "data", "powerlog_data.db")

for path, label in [(CHARGES_DB, "charging_data.db"), (POWERLOG_DB, "powerlog_data.db")]:
    if not os.path.exists(path):
        print(f"FEJL: {label} ikke fundet på {path}")
        exit(1)

charges_db  = sqlite3.connect(CHARGES_DB)
powerlog_db = sqlite3.connect(POWERLOG_DB)

today          = date.today()
current_start  = today.replace(day=1)
day_idx        = today.day - 1   # 0-based days elapsed in current month

# Previous month
prev_year  = today.year if today.month > 1 else today.year - 1
prev_month = today.month - 1 if today.month > 1 else 12
prev_start = date(prev_year, prev_month, 1)
prev_month_days = calendar.monthrange(prev_year, prev_month)[1]
prev_end   = date(prev_year, prev_month, min(today.day, prev_month_days))

# Same month last year
ly_year         = today.year - 1
ly_month_start  = date(ly_year, today.month, 1)
ly_month_days   = calendar.monthrange(ly_year, today.month)[1]
ly_month_end_d  = date(ly_year, today.month, ly_month_days)
ly_period_start = ly_month_start
ly_period_end   = date(ly_year, today.month, min(today.day, ly_month_days))

def d(dt): return dt.isoformat()

print("=== PERIODER ===")
print(f"  Nu (MTD):                      {d(current_start)} → {d(today)}  (dag {day_idx+1})")
print(f"  Forrige måned (MTD):           {d(prev_start)} → {d(prev_end)}")
print(f"  Samme måned i fjor (hele):     {d(ly_month_start)} → {d(ly_month_end_d)}")
print(f"  Samme måned i fjor (MTD):      {d(ly_period_start)} → {d(ly_period_end)}")
print()

# ── EV: interne ladninger ─────────────────────────────────────────────────────
print("=== ELBIL: INTERNE LADNINGER (charges) ===")
cur = charges_db.execute(
    "SELECT startedAt, stoppedAt, COALESCE(consumedKwh,0), COALESCE(cost,0) FROM charges "
    "WHERE DATE(startedAt) <= ? AND DATE(stoppedAt) >= ?",
    (d(today), d(ly_month_start))
)
rows = cur.fetchall()
print(f"  Rækker hentet fra DB: {len(rows)}")

int_month_kwh = int_month_cost = int_month_cnt = 0.0
int_prev_kwh  = int_prev_cost  = 0.0
int_ly_kwh    = int_ly_cost    = 0.0

for start_s, stop_s, kwh, cost in rows:
    try:
        if isinstance(start_s, (bytes, bytearray)): start_s = start_s.decode()
        if isinstance(stop_s,  (bytes, bytearray)): stop_s  = stop_s.decode()
        start_dt = datetime.fromisoformat(start_s.replace('Z','+00:00')).replace(tzinfo=None)
        stop_dt  = datetime.fromisoformat(stop_s.replace('Z','+00:00')).replace(tzinfo=None)
    except Exception as e:
        print(f"  ADVARSEL: Kan ikke parse datoer: {start_s} / {stop_s} — {e}")
        continue

    total_sec = max(1, (stop_dt - start_dt).total_seconds())
    counted_for_month = False
    cursor_d = start_dt.date()
    end_d    = stop_dt.date()
    start_ts = start_dt.timestamp()
    stop_ts  = stop_dt.timestamp()

    while cursor_d <= end_d:
        day_start_ts = datetime(cursor_d.year, cursor_d.month, cursor_d.day, 0, 0, 0).timestamp()
        day_end_ts   = datetime(cursor_d.year, cursor_d.month, cursor_d.day, 23, 59, 59).timestamp()
        slice_start  = max(start_ts, day_start_ts)
        slice_end    = min(stop_ts,  day_end_ts)
        slice_sec    = max(0, slice_end - slice_start)
        frac         = slice_sec / total_sec
        slice_kwh    = kwh  * frac
        slice_cost   = cost * frac

        if current_start <= cursor_d <= today:
            int_month_kwh  += slice_kwh
            int_month_cost += slice_cost
            if not counted_for_month:
                int_month_cnt += 1
                counted_for_month = True
        if prev_start <= cursor_d <= prev_end:
            int_prev_kwh  += slice_kwh
            int_prev_cost += slice_cost
        if ly_month_start <= cursor_d <= ly_month_end_d:
            int_ly_kwh  += slice_kwh
            int_ly_cost += slice_cost

        cursor_d += timedelta(days=1)

print(f"  Denne måned (MTD):     {round(int_month_kwh,2)} kWh / {round(int_month_cost,2)} kr  ({int(int_month_cnt)} ladninger)")
print(f"  Forrige måned (MTD):   {round(int_prev_kwh,2)} kWh / {round(int_prev_cost,2)} kr")
print(f"  Samme måned i fjor:    {round(int_ly_kwh,2)} kWh / {round(int_ly_cost,2)} kr")
print()

# ── EV: eksterne ladninger ────────────────────────────────────────────────────
print("=== ELBIL: EKSTERNE LADNINGER (ext_charges) ===")
try:
    cur2 = charges_db.execute(
        "SELECT datetime, kwh, pris FROM ext_charges WHERE datetime >= ? ORDER BY datetime",
        (d(ly_month_start),)
    )
    ext_rows = cur2.fetchall()
    print(f"  Rækker hentet: {len(ext_rows)}")
    if ext_rows:
        print(f"  Første: {ext_rows[0][0]}  Seneste: {ext_rows[-1][0]}")
        print("  Eksempler (datetime | kwh | pris):")
        for r in ext_rows[:5]:
            print(f"    {r[0]} | {r[1]} | {r[2]}")
except Exception as e:
    print(f"  ext_charges: {e}")
    ext_rows = []

ext_month_kwh = ext_month_cost = ext_month_cnt = 0.0
ext_prev_kwh  = ext_prev_cost  = 0.0
ext_ly_kwh    = ext_ly_cost    = 0.0

for dt_s, kwh, pris in ext_rows:
    try:
        dt_obj = datetime.fromisoformat(dt_s.replace('Z',''))
        dt_d   = dt_obj.date()
    except:
        print(f"  ADVARSEL: Kan ikke parse datetime: {dt_s}")
        continue
    kwh  = float(kwh  or 0)
    pris = float(pris or 0)
    if current_start <= dt_d <= today:
        ext_month_kwh  += kwh;  ext_month_cost += pris;  ext_month_cnt += 1
    if prev_start <= dt_d <= prev_end:
        ext_prev_kwh  += kwh;  ext_prev_cost += pris
    if ly_month_start <= dt_d <= ly_month_end_d:
        ext_ly_kwh  += kwh;  ext_ly_cost += pris

print(f"  Denne måned (MTD):   {round(ext_month_kwh,2)} kWh / {round(ext_month_cost,2)} kr  ({int(ext_month_cnt)} ladninger)")
print(f"  Forrige måned (MTD): {round(ext_prev_kwh,2)} kWh / {round(ext_prev_cost,2)} kr")
print(f"  Samme måned i fjor:  {round(ext_ly_kwh,2)} kWh / {round(ext_ly_cost,2)} kr")
print()

# ── Totaler ───────────────────────────────────────────────────────────────────
total_kwh  = int_month_kwh  + ext_month_kwh
total_cost = int_month_cost + ext_month_cost
prev_kwh   = int_prev_kwh   + ext_prev_kwh
prev_cost  = int_prev_cost  + ext_prev_cost
ly_kwh     = int_ly_kwh     + ext_ly_kwh
ly_cost    = int_ly_cost    + ext_ly_cost

def pct(a, b): return round((a - b) / b * 100, 1) if b > 0 else None

print("=== ELBIL: SAMLET ===")
print(f"  Denne måned (MTD):     {round(total_kwh,2)} kWh / {round(total_cost,2)} kr")
print(f"  Forrige måned (MTD):   {round(prev_kwh,2)} kWh / {round(prev_cost,2)} kr")
print(f"  Samme måned i fjor:    {round(ly_kwh,2)} kWh / {round(ly_cost,2)} kr")
print(f"  kWh vs. sidst måned:   {pct(total_kwh, prev_kwh)}%")
print(f"  kWh vs. sidste år:     {pct(total_kwh, ly_kwh)}%")
print(f"  Pris vs. sidst måned:  {pct(total_cost, prev_cost)}%")
print(f"  Pris vs. sidste år:    {pct(total_cost, ly_cost)}%")
print()

# ── Jordvarme ─────────────────────────────────────────────────────────────────
print("=== JORDVARME ===")

def hp_query(db, range_start, range_end, filter_start, filter_end):
    sql = """
    WITH daily_max AS (
        SELECT DATE(logdate) AS day, MAX(kwh) AS max_kwh
        FROM powerlogjord
        WHERE DATE(logdate) >= DATE(?, '-1 day')
          AND DATE(logdate) <= ?
        GROUP BY DATE(logdate)
    ),
    daily_delta AS (
        SELECT day,
               MAX(max_kwh - LAG(max_kwh) OVER (ORDER BY day), 0) AS delta_kwh
        FROM daily_max
    )
    SELECT ROUND(SUM(delta_kwh), 2)
    FROM daily_delta
    WHERE DATE(day) BETWEEN ? AND ?
    """
    row = db.execute(sql, (d(range_start), d(range_end), d(filter_start), d(filter_end))).fetchone()
    return float(row[0] or 0)

hp_current    = hp_query(powerlog_db, current_start,   today,           current_start,    today)
hp_prev       = hp_query(powerlog_db, prev_start,      prev_end,        prev_start,       prev_end)
hp_last_year  = hp_query(powerlog_db, ly_month_start,  ly_month_end_d,  ly_month_start,   ly_month_end_d)
hp_last_period= hp_query(powerlog_db, ly_period_start, ly_period_end,   ly_period_start,  ly_period_end)

print(f"  Denne måned (MTD):                          {round(hp_current,2)} kWh")
print(f"  Forrige måned (MTD til dag {day_idx+1}):            {round(hp_prev,2)} kWh")
print(f"  Samme måned i fjor (hele måneden):          {round(hp_last_year,2)} kWh")
print(f"  Samme måned i fjor (MTD til dag {day_idx+1}):       {round(hp_last_period,2)} kWh")
print(f"  % vs. sidst måned (MTD):                   {pct(hp_current, hp_prev)}%")
print(f"  % vs. samme måned i fjor (hel måned):      {pct(hp_current, hp_last_year)}%")
print(f"  % vs. samme måned i fjor (MTD):            {pct(hp_current, hp_last_period)}%")
print()

# ── Rådata-tjek ───────────────────────────────────────────────────────────────
print("=== RAW DATA TJEK ===")
r = powerlog_db.execute("SELECT COUNT(*), MIN(DATE(logdate)), MAX(DATE(logdate)) FROM powerlogjord").fetchone()
print(f"  powerlogjord:  {r[0]} rækker  fra {r[1]} til {r[2]}")
r2 = charges_db.execute("SELECT COUNT(*), MIN(DATE(startedAt)), MAX(DATE(stoppedAt)) FROM charges").fetchone()
print(f"  charges:       {r2[0]} rækker  fra {r2[1]} til {r2[2]}")
try:
    r3 = charges_db.execute("SELECT COUNT(*), MIN(datetime), MAX(datetime) FROM ext_charges").fetchone()
    print(f"  ext_charges:   {r3[0]} rækker  fra {r3[1]} til {r3[2]}")
except Exception as e:
    print(f"  ext_charges:   {e}")

print("\nFærdig.")
