# Contributing to php-ical-transition-rehearsal

Thank you for your interest in contributing to `php-ical-transition-rehearsal`!

## Architectural Principles

1. **Zero Unvetted Dependencies**: We vendor vetted Sabre VObject dependencies (`sabre/vobject`, `sabre/xml`, `sabre/uri`). Any additional package requires rigorous offline audit and qualification.
2. **Deterministic Offline Execution**: All builds, unit tests, recurrence evaluations, and oracle validations run strictly offline (`--network none`).
3. **UTC Surrogate Nominal Stepping**: Recurrence intervals and day steps must always evaluate against a UTC baseline before timezone application, preventing premature DST distortion.
4. **Physical Round-Trip Verification**: All candidate instants are validated by round-tripping through the target IANA timezone; non-existent hours yield zero candidates (gap), while ambiguous hours yield two candidates (fold).
5. **Bounded Resource Consumption**: Payloads exceeding 64 KiB, COUNT exceeding 100, durations exceeding 7 days, or unbounded recurrence intervals are rejected upfront.

## Development Workflow

```bash
# Run unit tests and self-test assertions offline
./scripts/test-offline.sh

# Run demo with self-test assertions
./scripts/demo-offline.sh --assert

# Run demo on specific ICS file with JSON output
./scripts/demo-offline.sh --ics tests/fixtures/ny_autumn_fold.ics --json
```

## Pull Request Guidelines

- Ensure `scripts/test-offline.sh` and `scripts/demo-offline.sh --assert` pass cleanly with zero warnings (`declare(strict_types=1)` enforced).
- Maintain code formatting conforming to PSR-12 and `.editorconfig`.
- Maintain test coverage for all edge cases including 30-minute transitions, spring gaps, and autumn folds.
