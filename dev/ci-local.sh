#!/usr/bin/env bash
# The CI steps (.github/workflows/tests.yml) run locally, in the same kind of environment as the CI runner:
# Linux, PHP 8.3 with pdo_sqlite, curl and mbstring. A PHP on a developer machine often lacks one of those
# (mbstring was missing here), and a test that passes there can fail in CI.
#
#   bash dev/ci-local.sh          # PHP syntax, unit tests, PHPStan  (needs Docker)
#
# JS syntax and ESLint are not run here (they need Node): npx eslint@8 --no-eslintrc -c dev/eslintrc.json "includes/*.js" "includes/elbil/*.js"
set -euo pipefail
cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1      # Git Bash on Windows must not rewrite /app into a Windows path
ROOT="$(pwd)"; command -v cygpath >/dev/null 2>&1 && ROOT="$(cygpath -w "$ROOT")"
PHP_IMAGE="php:8.3-cli"
PHPSTAN_IMAGE="ghcr.io/phpstan/phpstan:2.3.5"      # keep in step with .github/workflows/tests.yml

echo "== PHP syntax (php -l on every file)"
docker run --rm -v "$ROOT:/app" -w /app "$PHP_IMAGE" sh -c "find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l > /dev/null"
echo "ok"

echo "== unit tests"
docker run --rm -v "$ROOT:/app" -w /app "$PHP_IMAGE" php dev/run_tests.php

echo "== PHPStan"
docker run --rm -v "$ROOT:/app" -w /app "$PHPSTAN_IMAGE" analyse -c phpstan.neon --no-progress

echo "== all green"
