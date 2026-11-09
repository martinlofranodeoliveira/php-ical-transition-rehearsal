#!/bin/sh
set -e
if (set -o pipefail 2>/dev/null); then
    set -o pipefail
fi
set -u

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "${PROJECT_ROOT}"

# Run unit tests and self-test assertion suite
php bin/ical-rehearsal.php --assert --oracle
