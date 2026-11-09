# Architectural Design

## 1. The Recurrence Transition Challenge

Evaluating recurring calendar events across daylight saving time (DST) transitions is notoriously error-prone:
- Generating occurrences directly in local timezone time can cause recurrence iterators to stumble on missing hours (gaps) or duplicate hours (folds), producing unpredictable skips, endless loops, or incorrect offsets.
- Converting event start times to UTC prior to recurrence expansion distorts civil intent: an event scheduled for "09:00 local time every day" should remain at 09:00 on clock walls, but converting to UTC fixes the physical offset prematurely, causing civil start times to jump by 1 hour when DST shifts.

## 2. Decoupled Two-Phase Evaluation Architecture

`php-ical-transition-rehearsal` solves this through a strictly decoupled, two-phase pipeline:

```
[ Raw ICS Payload ]
        │
        ▼
 Phase 1: IcsParser (Security & Profile Validation)
        │
        ▼
 Phase 2: NominalIterator (UTC Surrogate Stepper)
   Generates civil labels: ["2026-03-07 02:30:00", "2026-03-08 02:30:00", ...]
        │
        ▼
 Phase 3: TimezoneResolver (Physical Candidate Formation)
   Probes native IANA transitions in ±2 day window
   Forms round-trip candidates for each nominal label
        │
        ├─ 0 candidates (Gap)  ──> Applied Policy: reject-series | skip-instance
        ├─ 1 candidate  (Norm) ──> Resolved Single Instant
        └─ 2 candidates (Fold) ──> Applied Policy: earlier-instant | later-instant
        │
        ▼
 Phase 4: OracleValidator (System zdump Evidence)
        │
        ▼
[ Structured RehearsalResult JSON ]
```

### Phase 1: Strict Profile Validation
`IcsParser` validates payload boundaries:
- Payload size limited to 64 KiB.
- Single `VEVENT` component only; overrides and custom `VTIMEZONE` blocks are rejected.
- `DTSTART` must provide explicit UTC `Z` suffix or an approved IANA `TZID`.
- Strict check on `DURATION` (strictly positive, $\le 7$ days).
- Rejection of unbound recurrences (`UNTIL`, missing `COUNT`, or `COUNT > 100`).

### Phase 2: UTC Surrogate Nominal Stepping
To decouple civil recurrence semantics from timezone transitions, `NominalIterator` anchors the event to a synthetic UTC timestamp (`createFromFormat('Y-m-d H:i:s', $label, new DateTimeZone('UTC'))`).

Because UTC has no leap seconds, daylight shifts, or historical irregularities in calendar math, Sabre VObject's `RRuleIterator` steps purely in uniform civil day/week intervals. The output is a deterministic list of nominal civil timestamps:
`"YYYY-MM-DD HH:MM:SS"`.

### Phase 3: Physical Candidate Formation & Policy Application
For each nominal label, `TimezoneResolver`:
1. Queries native PHP `DateTimeZone::getTransitions()` over a bounded $\pm 2$ day window around the nominal date.
2. Identifies all active offsets in that window.
3. Tests each offset by constructing a test instant and round-tripping through the target timezone:
   ```php
   $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s P', "{$nominalLabel} {$offsetString}");
   $inTz = $dt->setTimezone($tz);
   if ($inTz->format('Y-m-d H:i:s') === $nominalLabel && $inTz->getOffset() === $off) {
       $candidates[] = $inTz;
   }
   ```
4. Categorizes the multiplicity $k = |\text{candidates}|$:
   - $k = 0$: Spring forward gap. Clocks jumped over this time. Enforces `reject-series` (throws `GapException`) or `skip-instance` (records occurrence as `SKIPPED`).
   - $k = 1$: Standard unambiguous instant.
   - $k = 2$: Autumn fold. Clocks fell back. Selects candidate 0 (`earlier-instant`) or candidate 1 (`later-instant`).
   - $k > 2$: Anomaly guard; raises `UnexpectedMultiplicityException`.

### Phase 4: Oracle Verification
`OracleValidator` invokes the host system's `zdump` CLI utility to cross-examine transition timestamps and verify that the PHP runtime's internal tzdata matches the host system's zoneinfo database.
