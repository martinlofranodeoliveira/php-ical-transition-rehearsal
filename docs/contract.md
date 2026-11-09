# Recurrence Contract and Boundary Guarantees

`php-ical-transition-rehearsal` guarantees deterministic recurrence evaluation and strict boundary enforcement under the following constraints:

## 1. Input Bounds & Resource Limits

| Parameter | Limit | Enforcement Point | Description |
| :--- | :--- | :--- | :--- |
| `max_input_bytes` | 65,536 bytes (64 KiB) | `IcsParser::parse()` | Maximum raw `.ics` payload size |
| `max_vevents` | 1 | `IcsParser::parse()` | Exactly one `VEVENT` allowed; overrides rejected |
| `max_count` | 100 occurrences | `IcsParser::parse()` | Bound on RRULE `COUNT` parameter |
| `min_count` | 1 occurrence | `IcsParser::parse()` | Strictly positive recurrence count |
| `max_duration` | 7 days (P7D) | `IcsParser::parse()` | Maximum event elapsed duration |
| `min_duration` | > 0 seconds | `IcsParser::parse()` | Non-zero, strictly positive duration |

## 2. Recurrence Profile Constraints

To guarantee termination and avoid exponential recurrence expansions, the following RFC 5545 features are strictly forbidden:
- `UNTIL`: Replaced by mandatory `COUNT` to prevent infinite series evaluations.
- `RDATE`: Explicit additional dates are rejected; all occurrences derive from `RRULE`.
- Floating times: Local times without `Z` or `TZID` parameter are rejected.
- Recurrence overrides: Documents with multiple `VEVENT` or `RECURRENCE-ID` components are rejected.
- Custom `VTIMEZONE`: Inlined custom daylight definitions are rejected; all timezones must be qualified IANA TZIDs.
- Secondary RRULE filters: `BYSETPOS`, `BYSECOND`, `BYMINUTE`, `BYHOUR`, `BYMONTHDAY`, `BYYEARDAY`, `BYWEEKNO`, `BYMONTH` are disallowed in the base profile.
- Permitted frequencies: `FREQ=DAILY` and `FREQ=WEEKLY` (with optional non-numeric `BYDAY`).

## 3. Timezone Allowlist

The engine verifies timezones against a qualified set of IANA canonical identifiers:
- `UTC`
- `America/New_York`
- `America/Sao_Paulo`
- `Europe/London`
- `Europe/Paris`
- `Australia/Lord_Howe`
- `Asia/Tokyo`
- `America/Los_Angeles`
- `America/Chicago`

## 4. Multiplicity & Policy Invariants

For each nominal civil date-time $T_{\text{nominal}}$:
1. **Singleton ($k = 1$)**: Exactly one UTC instant produces the nominal civil representation in the target timezone. Status is `RESOLVED` with applied policy `single`.
2. **Gap ($k = 0$)**: Clocks skipped over $T_{\text{nominal}}$ during a daylight saving jump forward.
   - Under `reject-series`: Execution fails immediately with status `GAP_REJECTED`.
   - Under `skip-instance`: Occurrence is marked `SKIPPED` with reason `dst_gap`; subsequent occurrences continue evaluating.
3. **Fold ($k = 2$)**: Clocks were set back, creating two distinct UTC instants sharing the same civil clock time.
   - Under `earlier-instant`: Candidate 0 (the earlier physical instant, before clock rollback) is selected.
   - Under `later-instant`: Candidate 1 (the later physical instant, after clock rollback) is selected.
4. **Non-Standard Shifts**: Shifts that alter timezone offsets by non-hour intervals (such as Australia/Lord_Howe's 30-minute shift between $+11:00$ and $+10:30$) are natively supported without hardcoded 3600-second step assumptions.
