# Changelog

All notable changes to `php-ical-transition-rehearsal` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-11-09

### Added
- Initial implementation of bounded iCalendar recurrence rehearsal in PHP 8.4 with Sabre VObject.
- Strict bounded parser (`IcsParser`) enforcing <=64 KiB payload, single VEVENT, bounded COUNT (1..100), explicit positive DURATION, and qualified IANA TZIDs.
- UTC surrogate nominal recurrence stepping (`NominalIterator`) preventing premature DST shifting.
- Multiplicity-aware timezone candidate instant resolver (`TimezoneResolver`) detecting spring gaps (0 candidates) and autumn folds (2 candidates).
- Configurable policies for spring gaps (`reject-series`, `skip-instance`) and autumn folds (`earlier-instant`, `later-instant`).
- Native support for non-hour transitions including Australia/Lord_Howe 30-minute shifts (+11:00 to +10:30).
- System `zdump` oracle verification runner (`OracleValidator`).
- Comprehensive CLI executable (`bin/ical-rehearsal.php`) with `--assert`, `--json`, `--ics`, `--gap-policy`, `--fold-policy`, and `--oracle`.
- 10 synthetic test fixtures and full test suite (`tests/IcalRehearsalTest.php`).
- Standalone offline test and demo execution scripts (`scripts/test-offline.sh`, `scripts/demo-offline.sh`).
- GitHub Actions CI workflow with offline qualification.
