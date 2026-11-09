# php-ical-transition-rehearsal

[![CI](https://github.com/martinlofranodeoliveira/php-ical-transition-rehearsal/actions/workflows/ci.yml/badge.svg)](https://github.com/martinlofranodeoliveira/php-ical-transition-rehearsal/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP 8.4](https://img.shields.io/badge/PHP-8.4-blue.svg)](https://www.php.net/)

Bounded iCalendar (RFC 5545) recurrence transition rehearsal and daylight saving time (DST) auditing engine in PHP 8.4 with Sabre VObject.

Read this in [Português](README.pt-BR.md).

## Features

- **Decoupled Nominal Stepping**: Employs a UTC surrogate anchor with Sabre VObject's `RRuleIterator` to generate nominal civil labels without premature DST corruption.
- **Physical Multiplicity Resolution**: Resolves each nominal occurrence against native PHP `DateTimeZone` transitions to detect exact candidate instants.
- **Gap & Fold Policies**:
  - **Spring Gaps (0 candidates)**: Choose between `reject-series` (strict rejection) and `skip-instance` (omit missing time with `SKIPPED` status).
  - **Autumn Folds (2 candidates)**: Deterministically select between `earlier-instant` and `later-instant`.
- **Non-Hour Transitions**: Full native support for 30-minute daylight saving shifts (e.g. `Australia/Lord_Howe` $+11:00 \leftrightarrow +10:30$) and historical shifts.
- **Strict Bounded Profile**: Caps input size at 64 KiB, COUNT at 100, and durations at 7 days; rejects unsupported RFC 5545 extensions (`UNTIL`, `RDATE`, floating time, recurrence overrides).
- **Oracle Verification**: Built-in verification against host system `zdump` and native `tzdata` transitions.
- **Deterministic & Offline**: Zero unvetted external dependencies; verified to run in strictly isolated offline environments (`--network none`).

## Quick Start

### Run Tests and Assertions Offline

```bash
# Execute offline test suite and assertions
./scripts/test-offline.sh

# Run self-test assertions directly
./scripts/demo-offline.sh --assert

# Run assertions with system zdump oracle verification
./scripts/demo-offline.sh --assert --oracle
```

### Command Line Interface

```bash
# Evaluate an iCalendar file with JSON output
php bin/ical-rehearsal.php --ics tests/fixtures/ny_autumn_fold.ics --json

# Resolve autumn fold using later-instant policy
php bin/ical-rehearsal.php --ics tests/fixtures/ny_autumn_fold.ics --fold-policy later-instant --json

# Handle spring gaps by skipping missing instances
php bin/ical-rehearsal.php --ics tests/fixtures/ny_spring_gap.ics --gap-policy skip-instance --json
```

## Programmatic Usage

```php
use Lofrano\IcalRehearsal\IcalRehearsal;

$rawIcs = file_get_contents('event.ics');

// Rehearse series with strict rejection on gap
$result = IcalRehearsal::rehearse(
    rawIcs: $rawIcs,
    gapPolicy: 'reject-series',
    foldPolicy: 'earlier-instant'
);

if ($result->success) {
    foreach ($result->occurrences as $occ) {
        echo "{$occ->nominalLabel} -> {$occ->selectedInstant} ({$occ->status})\n";
    }
} else {
    echo "Rehearsal failed: {$result->message}\n";
}
```

## Architecture & Specifications

For comprehensive technical specifications and boundary contracts, see:
- [Contract & Resource Limits](docs/contract.md)
- [Architectural Design](docs/design.md)
- [Product Vision](PRODUCT.md)

## License

This project is licensed under the [MIT License](LICENSE) © 2026 Lofrano Tecnologia.
