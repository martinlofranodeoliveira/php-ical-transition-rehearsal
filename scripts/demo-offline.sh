#!/bin/sh
set -e
if (set -o pipefail 2>/dev/null); then
    set -o pipefail
fi
set -u

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "${PROJECT_ROOT}"

if [ "$#" -eq 0 ]; then
    php bin/ical-rehearsal.php --ics tests/fixtures/ny_autumn_fold.ics --json
else
    php bin/ical-rehearsal.php "$@"
fi
