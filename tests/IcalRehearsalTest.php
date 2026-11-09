<?php

declare(strict_types=1);

namespace Lofrano\IcalRehearsal\Tests;

use Lofrano\IcalRehearsal\IcalRehearsal;
use Lofrano\IcalRehearsal\IcsParser;
use Lofrano\IcalRehearsal\OracleValidator;

final class IcalRehearsalTest
{
    private string $fixturesDir;
    private int $testsRun = 0;
    private int $testsPassed = 0;
    private array $failures = [];

    public function __construct()
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
    }

    public function runAllTests(bool $silent = false): bool
    {
        $methods = [
            'testUtcDaily',
            'testNySpringGapReject',
            'testNySpringGapSkip',
            'testNyAutumnFoldEarlier',
            'testNyAutumnFoldLater',
            'testLordHowe30MinTransition',
            'testHistoricalTransitionSaoPaulo',
            'testExdateHandling',
            'testNegativeValidationRejections',
            'testWeeklyRecurrenceWithByDay',
            'testOracleVerification',
        ];

        foreach ($methods as $method) {
            $this->testsRun++;
            try {
                $this->$method();
                $this->testsPassed++;
                if (!$silent) {
                    echo "  [PASS] {$method}\n";
                }
            } catch (\Throwable $e) {
                $this->failures[] = [
                    'test' => $method,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ];
                if (!$silent) {
                    echo "  [FAIL] {$method}: {$e->getMessage()}\n";
                }
            }
        }

        if (!$silent) {
            echo sprintf("\nTest summary: %d/%d passed.\n", $this->testsPassed, $this->testsRun);
        }

        return count($this->failures) === 0;
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: {$message}");
        }
    }

    private function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $msg = $message !== '' ? " ({$message})" : '';
            throw new \RuntimeException(sprintf("Expected %s, got %s%s", json_encode($expected), json_encode($actual), $msg));
        }
    }

    public function testUtcDaily(): void
    {
        $icsFile = $this->fixturesDir . '/utc_daily.ics';
        $res = IcalRehearsal::rehearseFile($icsFile);

        $this->assert($res->success, 'UTC daily rehearsal must succeed');
        $this->assertEquals(5, $res->nominalCount, 'Nominal count should be 5');
        $this->assertEquals(5, $res->resolvedCount, 'Resolved count should be 5');
        $this->assertEquals(0, $res->skippedCount, 'Skipped count should be 0');
        $this->assertEquals(0, $res->excludedCount, 'Excluded count should be 0');
        $this->assertEquals('utc-daily-001@lofrano.com.br', $res->uid);

        $first = $res->occurrences[0];
        $this->assertEquals('2026-06-01 09:00:00', $first->nominalLabel);
        $this->assertEquals('2026-06-01T09:00:00+00:00', $first->selectedInstant);
        $this->assertEquals('2026-06-01T10:00:00+00:00', $first->durationEnd);
        $this->assertEquals(0, $first->selectedOffset);
        $this->assertEquals('RESOLVED', $first->status);
    }

    public function testNySpringGapReject(): void
    {
        $icsFile = $this->fixturesDir . '/ny_spring_gap.ics';
        $res = IcalRehearsal::rehearseFile($icsFile, gapPolicy: 'reject-series');

        $this->assert(!$res->success, 'Rehearsal must fail under reject-series on DST gap');
        $this->assertEquals('GAP_REJECTED', $res->status);
        $this->assert(str_contains($res->message, '2026-03-08 02:30:00'), 'Error message must specify gap date');
    }

    public function testNySpringGapSkip(): void
    {
        $icsFile = $this->fixturesDir . '/ny_spring_gap.ics';
        $res = IcalRehearsal::rehearseFile($icsFile, gapPolicy: 'skip-instance');

        $this->assert($res->success, 'Rehearsal must succeed under skip-instance');
        $this->assertEquals(5, $res->nominalCount);
        $this->assertEquals(4, $res->resolvedCount);
        $this->assertEquals(1, $res->skippedCount);

        // Occurrence 3 corresponds to 2026-03-08 02:30:00
        $gapOcc = $res->occurrences[2];
        $this->assertEquals('2026-03-08 02:30:00', $gapOcc->nominalLabel);
        $this->assertEquals('SKIPPED', $gapOcc->status);
        $this->assertEquals('dst_gap', $gapOcc->reason);
        $this->assertEquals(0, count($gapOcc->candidateInstants));
        $this->assert($gapOcc->selectedInstant === null, 'Selected instant must be null for skipped gap');
    }

    public function testNyAutumnFoldEarlier(): void
    {
        $icsFile = $this->fixturesDir . '/ny_autumn_fold.ics';
        $res = IcalRehearsal::rehearseFile($icsFile, foldPolicy: 'earlier-instant');

        $this->assert($res->success, 'Fold rehearsal must succeed');
        $this->assertEquals(5, $res->resolvedCount);

        // Occurrence 3 corresponds to 2026-11-01 01:30:00
        $foldOcc = $res->occurrences[2];
        $this->assertEquals('2026-11-01 01:30:00', $foldOcc->nominalLabel);
        $this->assertEquals(2, count($foldOcc->candidateInstants), 'Must detect exactly 2 candidates during DST fold');
        $this->assertEquals('2026-11-01T01:30:00-04:00', $foldOcc->selectedInstant, 'Earlier instant selected');
        $this->assertEquals(-14400, $foldOcc->selectedOffset, 'Offset must be -04:00 (-14400)');
        $this->assertEquals('earlier-instant', $foldOcc->appliedPolicy);
    }

    public function testNyAutumnFoldLater(): void
    {
        $icsFile = $this->fixturesDir . '/ny_autumn_fold.ics';
        $res = IcalRehearsal::rehearseFile($icsFile, foldPolicy: 'later-instant');

        $this->assert($res->success, 'Fold rehearsal must succeed');
        $foldOcc = $res->occurrences[2];
        $this->assertEquals('2026-11-01 01:30:00', $foldOcc->nominalLabel);
        $this->assertEquals(2, count($foldOcc->candidateInstants));
        $this->assertEquals('2026-11-01T01:30:00-05:00', $foldOcc->selectedInstant, 'Later instant selected');
        $this->assertEquals(-18000, $foldOcc->selectedOffset, 'Offset must be -05:00 (-18000)');
        $this->assertEquals('later-instant', $foldOcc->appliedPolicy);
    }

    public function testLordHowe30MinTransition(): void
    {
        $icsFile = $this->fixturesDir . '/lord_howe_30min.ics';
        $res = IcalRehearsal::rehearseFile($icsFile, foldPolicy: 'earlier-instant');

        $this->assert($res->success, 'Lord Howe rehearsal must succeed');
        $this->assertEquals(4, $res->resolvedCount);

        // Occurrence 3 is on Sun Apr 5, 2026 at 01:45:00
        $foldOcc = $res->occurrences[2];
        $this->assertEquals('2026-04-05 01:45:00', $foldOcc->nominalLabel);
        $this->assertEquals(2, count($foldOcc->candidateInstants), 'Lord Howe fold has 2 candidate instants');
        // Candidate 0: +11:00 (39600s), Candidate 1: +10:30 (37800s)
        $this->assertEquals(39600, $foldOcc->selectedOffset);
        $this->assertEquals('2026-04-05T01:45:00+11:00', $foldOcc->selectedInstant);

        // Later instant selection
        $resLater = IcalRehearsal::rehearseFile($icsFile, foldPolicy: 'later-instant');
        $foldLater = $resLater->occurrences[2];
        $this->assertEquals(37800, $foldLater->selectedOffset);
        $this->assertEquals('2026-04-05T01:45:00+10:30', $foldLater->selectedInstant);
    }

    public function testHistoricalTransitionSaoPaulo(): void
    {
        $icsFile = $this->fixturesDir . '/sao_paulo_historical.ics';
        $res = IcalRehearsal::rehearseFile($icsFile, foldPolicy: 'earlier-instant');

        $this->assert($res->success, 'Sao Paulo 2018 historical rehearsal must succeed');
        // Occurrence 2 is on 2018-02-17 23:30:00
        $foldOcc = $res->occurrences[1];
        $this->assertEquals('2018-02-17 23:30:00', $foldOcc->nominalLabel);
        $this->assertEquals(2, count($foldOcc->candidateInstants), 'Historical fold must detect 2 candidates (-02:00 and -03:00)');
        $this->assertEquals(-7200, $foldOcc->selectedOffset);
        $this->assertEquals('2018-02-17T23:30:00-02:00', $foldOcc->selectedInstant);
    }

    public function testExdateHandling(): void
    {
        $icsFile = $this->fixturesDir . '/exdate_daily.ics';
        $res = IcalRehearsal::rehearseFile($icsFile);

        $this->assert($res->success, 'Exdate rehearsal must succeed');
        $this->assertEquals(5, $res->nominalCount);
        $this->assertEquals(4, $res->resolvedCount);
        $this->assertEquals(1, $res->excludedCount);

        // Occurrence 3 is excluded
        $exOcc = $res->occurrences[2];
        $this->assertEquals('2026-06-03 10:00:00', $exOcc->nominalLabel);
        $this->assertEquals('EXCLUDED', $exOcc->status);
        $this->assertEquals('exdate_match', $exOcc->reason);
        $this->assert($exOcc->selectedInstant === null, 'Selected instant must be null for excluded occurrence');
    }

    public function testNegativeValidationRejections(): void
    {
        $files = [
            'invalid_until.ics' => 'UNTIL',
            'invalid_floating.ics' => 'Floating',
            'invalid_override.ics' => 'multiple VEVENTs',
            'invalid_rdate.ics' => 'RDATE',
        ];

        foreach ($files as $file => $expectedSubstring) {
            $path = $this->fixturesDir . '/' . $file;
            $res = IcalRehearsal::rehearseFile($path);
            $this->assert(!$res->success, "File {$file} must be rejected");
            $this->assertEquals('INVALID_ICS', $res->status);
            $this->assert(
                str_contains($res->message, $expectedSubstring),
                "Error for {$file} must mention '{$expectedSubstring}', got '{$res->message}'"
            );
        }
    }

    public function testWeeklyRecurrenceWithByDay(): void
    {
        $ics = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Lofrano Tecnologia//iCal Rehearsal//EN
BEGIN:VEVENT
UID:weekly-byday-test@lofrano.com.br
DTSTART;TZID=America/New_York:20260601T140000
DURATION:PT1H
RRULE:FREQ=WEEKLY;BYDAY=MO,WE,FR;COUNT=6
SUMMARY:Weekly Mon-Wed-Fri Sync
END:VEVENT
END:VCALENDAR
ICS;

        $res = IcalRehearsal::rehearse($ics);
        $this->assert($res->success, 'Weekly rehearsal must succeed');
        $this->assertEquals(6, $res->nominalCount);
        $this->assertEquals(6, $res->resolvedCount);

        $labels = array_map(fn($o) => $o->nominalLabel, $res->occurrences);
        $expected = [
            '2026-06-01 14:00:00', // Monday
            '2026-06-03 14:00:00', // Wednesday
            '2026-06-05 14:00:00', // Friday
            '2026-06-08 14:00:00', // Monday
            '2026-06-10 14:00:00', // Wednesday
            '2026-06-12 14:00:00', // Friday
        ];
        $this->assertEquals($expected, $labels);
    }

    public function testOracleVerification(): void
    {
        $oracle = OracleValidator::verifyOracle('America/New_York');
        $this->assertEquals('PASS', $oracle['status']);
        $this->assert($oracle['zdump_available'], 'zdump should be available in testing environment');
        $this->assert(!empty($oracle['timezone_version']), 'timezone_version must be populated');
    }
}
