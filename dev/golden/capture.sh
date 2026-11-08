#!/usr/bin/env bash
# Golden capture of the dashboard (and optionally other pure builders) at frozen instants, in Docker.
#
#   bash dev/golden/capture.sh <label> <dir-with-databases> [<dir-with-databases> ...]
#
# Each <dir> holds charging_data.db and powerlog_data.db (a copy of production, or a dev/seed_dev.php seed).
# Output: dev/golden/out/<label>/<dir-name>__<instant>.json. Capture with label "before", change the code,
# capture with label "after", then:   diff -r dev/golden/out/before dev/golden/out/after
#
# Needs Docker. The image has PHP 8.3 and libfaketime; it is built on first use.
set -euo pipefail
cd "$(dirname "$0")/../.."
export MSYS_NO_PATHCONV=1
LABEL="${1:?label}"; shift
[ $# -ge 1 ] || { echo "give at least one database directory"; exit 1; }

IMAGE=sparkspend-faketime
if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
  printf 'FROM php:8.3-cli\nRUN apt-get update -qq && apt-get install -y -qq faketime >/dev/null && rm -rf /var/lib/apt/lists/*\n' | docker build -q -t "$IMAGE" - >/dev/null
fi

ROOT="$(pwd)"; command -v cygpath >/dev/null 2>&1 && ROOT="$(cygpath -w "$ROOT")"
OUT="dev/golden/out/$LABEL"; rm -rf "$OUT"; mkdir -p "$OUT"

# instants chosen to hit the edges: ordinary day, 1st of the month just after midnight, month end after a
# shorter previous month, the 23-hour and the 25-hour DST day, the new year (last-year windows), a leap day (local time)
INSTANTS=("2026-11-08 12:00:00" "2026-11-01 00:20:00" "2026-03-31 23:50:00" "2026-03-29 12:00:00" "2026-10-25 12:00:00" "2027-01-01 01:00:00" "2028-02-29 18:00:00")

for dbdir in "$@"; do
  name="$(basename "$dbdir")"; abs="$(cd "$dbdir" && pwd)"; [ -n "$(command -v cygpath)" ] && abs="$(cygpath -w "$abs")"
  for at in "${INSTANTS[@]}"; do
    file="$OUT/${name}__${at// /_}.json"; file="${file//:/-}"
    # -f: a FROZEN clock (without it the clock keeps running from the given instant). TZ: the instant is local time.
    docker run --rm -v "$ROOT:/app" -v "$abs:/data" -w /app -e TZ=Europe/Copenhagen \
      -e SPARKSPEND_ENV_FILE=/app/dev/golden/golden.env "$IMAGE" faketime -f "$at" php dev/golden/dashboard_snapshot.php > "$file" 2>"$file.err" || { echo "FAILED at $at for $name"; cat "$file.err"; exit 1; }
  done
  echo "captured $name: ${#INSTANTS[@]} instants"
done
echo "-> $OUT"
