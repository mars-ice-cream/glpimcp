#!/usr/bin/env bash
# Lance la suite de bout en bout contre le GLPI préparé par ci_setup.sh.
#   WORK=/tmp/glpimcp-e2e bash tests/e2e/ci_run.sh [-k motif]
set -euo pipefail
WORK="${WORK:-${RUNNER_TEMP:-/tmp}/glpimcp-e2e}"
set -a
# shellcheck disable=SC1091
. "$WORK/e2e.env"
set +a
python3 "$(dirname "$0")/run_tests.py" -v "$@"
