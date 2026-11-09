# PRODUCT.md - php-ical-transition-rehearsal

## Overview

`php-ical-transition-rehearsal` is a deterministic, offline recurrence rehearsal and timezone transition auditing engine for iCalendar (RFC 5545) events in PHP 8.4 with Sabre VObject.

In enterprise calendaring platforms, scheduling microservices, and booking systems, recurring events crossing daylight saving time (DST) transitions frequently trigger silent scheduling bugs:
- **Spring Forward Gaps**: Clocks advance by 1 hour (or 30 minutes), skipping nominal meeting times (e.g., 02:30 AM). Naive parsers silently shift meetings forward, reschedule them to wrong days, or cause duplicate bookings.
- **Autumn Back Folds**: Clocks repeat the same civil hour (e.g., 01:30 AM occurs twice). Systems silently pick arbitrary offsets, causing attendees to show up an hour early or late.
- **Non-Hour Shifts**: Rare but real timezones like `Australia/Lord_Howe` transition by 30 minutes, breaking engines that hardcode 3600-second offsets.

`php-ical-transition-rehearsal` solves these challenges through deterministic recurrence rehearsal. It uses a UTC surrogate to step nominal civil labels without premature DST corruption, probes native IANA timezone transitions to discover exact physical candidate instants, and applies strict, declarative policies for gaps and folds.

## Key Capabilities

1. **Strict Bounded Profile**:
   - Caps ICS document size at 64 KiB to prevent memory exhaustion.
   - Enforces single-event (`VEVENT`) validation; rejects unsupported multi-event overrides and custom `VTIMEZONE` payloads.
   - Binds recurrence count (`COUNT` between 1 and 100) and limits event duration to positive intervals <= 7 days.
   - Rejects unsupported RFC 5545 features (`UNTIL`, `RDATE`, `BYSETPOS`, etc.) upfront.

2. **UTC Surrogate Nominal Stepping**:
   - Uses Sabre VObject's `RRuleIterator` against a UTC anchor to step nominal civil timestamps (`YYYY-MM-DD HH:MM:SS`).
   - Completely avoids timezone skew during intermediate stepping intervals.

3. **Multiplicity-Aware Instant Resolution**:
   - Discovers all valid physical UTC instants matching each civil label by querying native PHP 8.4 `DateTimeZone` transitions.
   - Accurately classifies occurrences into singletons (1 candidate), gaps (0 candidates), or folds (2 candidates).
   - Supports 30-minute transitions (`Australia/Lord_Howe`) and historical shifts without hardcoded 1-hour assumptions.

4. **Configurable Transition Policies**:
   - **Gap Policy**: Choose `reject-series` (fail validation when a non-existent hour is scheduled) or `skip-instance` (omit the invalid occurrence with `SKIPPED` provenance).
   - **Fold Policy**: Select `earlier-instant` or `later-instant` to deterministically resolve ambiguous fold timestamps.

5. **Oracle Verification & Provenance**:
   - Validates transition evidence against system `zdump` and native `tzdata`.
   - Generates structured JSON reports detailing input parameters, resolved UTC instants, physical durations, and runtime provenance.
