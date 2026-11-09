#!/usr/bin/env php
<?php

declare(strict_types=1);

// Locate Composer autoloader
$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];

$loaded = false;
foreach ($autoloadCandidates as $candidate) {
    if (file_exists($candidate)) {
        require_once $candidate;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    fwrite(STDERR, "Error: vendor/autoload.php not found. Run 'composer install' first.\n");
    exit(1);
}

use Lofrano\IcalRehearsal\IcalRehearsal;
use Lofrano\IcalRehearsal\OracleValidator;

function printHelp(): void
{
    echo <<<HELP
iCalendar Transition Rehearsal CLI - PHP 8.4 + Sabre VObject

Usage:
  ical-rehearsal.php [options]

Options:
  --ics <path>                 Path to an iCalendar (.ics) file
  --gap-policy <policy>        DST gap policy: reject-series | skip-instance (default: reject-series)
  --fold-policy <policy>       DST fold policy: earlier-instant | later-instant (default: earlier-instant)
  --assert                     Run self-test assertion suite against built-in fixtures
  --oracle                     Execute system zdump oracle verification and output transition evidence
  --json                       Emit JSON-formatted output
  --help                       Show this help message

Exit codes:
  0: Success / assertions passed
  1: Execution error / assertion failed
  2: Invalid arguments / invalid ICS format

HELP;
}

$shortopts = "h";
$longopts = [
    "ics:",
    "gap-policy:",
    "fold-policy:",
    "assert",
    "oracle",
    "json",
    "help",
];

$options = getopt($shortopts, $longopts);

if (isset($options['h']) || isset($options['help'])) {
    printHelp();
    exit(0);
}

$asJson = isset($options['json']);
$doAssert = isset($options['assert']);
$doOracle = isset($options['oracle']);
$icsPath = $options['ics'] ?? null;
$gapPolicy = $options['gap-policy'] ?? 'reject-series';
$foldPolicy = $options['fold-policy'] ?? 'earlier-instant';

if (!in_array($gapPolicy, ['reject-series', 'skip-instance'], true)) {
    fwrite(STDERR, "Error: Invalid --gap-policy '{$gapPolicy}'. Allowed values: reject-series, skip-instance\n");
    exit(2);
}

if (!in_array($foldPolicy, ['earlier-instant', 'later-instant'], true)) {
    fwrite(STDERR, "Error: Invalid --fold-policy '{$foldPolicy}'. Allowed values: earlier-instant, later-instant\n");
    exit(2);
}

if ($doAssert) {
    // Run self-test suite
    $testScript = __DIR__ . '/../tests/IcalRehearsalTest.php';
    if (!file_exists($testScript)) {
        fwrite(STDERR, "Error: Test runner not found at {$testScript}\n");
        exit(1);
    }

    require_once $testScript;
    $runner = new \Lofrano\IcalRehearsal\Tests\IcalRehearsalTest();
    $passed = $runner->runAllTests($asJson);

    if ($doOracle) {
        $oracleResult = OracleValidator::verifyOracle('America/New_York');
        if ($asJson) {
            echo json_encode(['oracle_verification' => $oracleResult], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            echo "\n--- Oracle Verification (America/New_York) ---\n";
            echo "zdump available: " . ($oracleResult['zdump_available'] ? 'Yes' : 'No') . "\n";
            echo "tzdata version:  " . $oracleResult['timezone_version'] . "\n";
            echo "transitions:     " . $oracleResult['transitions_found'] . " found\n";
            echo "status:          " . $oracleResult['status'] . "\n";
        }
    }

    exit($passed ? 0 : 1);
}

if ($icsPath !== null) {
    if (!file_exists($icsPath)) {
        fwrite(STDERR, "Error: Specified --ics file does not exist: {$icsPath}\n");
        exit(2);
    }

    $result = IcalRehearsal::rehearseFile($icsPath, $gapPolicy, $foldPolicy);

    if ($doOracle && $result->provenance['tzid'] !== 'UNKNOWN') {
        $oracleResult = OracleValidator::verifyOracle($result->provenance['tzid']);
        $resultArr = $result->toArray();
        $resultArr['oracle_evidence'] = $oracleResult;
        if ($asJson) {
            echo json_encode($resultArr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            printResultHuman($result);
            echo "\n--- Oracle Verification ({$result->provenance['tzid']}) ---\n";
            echo "zdump available: " . ($oracleResult['zdump_available'] ? 'Yes' : 'No') . "\n";
            echo "tzdata version:  " . $oracleResult['timezone_version'] . "\n";
            echo "transitions:     " . $oracleResult['transitions_found'] . " found\n";
        }
    } else {
        if ($asJson) {
            echo $result->toJson() . "\n";
        } else {
            printResultHuman($result);
        }
    }

    exit($result->success ? 0 : 1);
}

// If no arguments provided, show help and run a short demo
if (empty($options)) {
    printHelp();
    exit(0);
}

function printResultHuman(\Lofrano\IcalRehearsal\RehearsalResult $res): void
{
    echo "========================================================\n";
    echo " iCalendar Recurrence Transition Rehearsal Report\n";
    echo "========================================================\n";
    echo "Status:          " . $res->status . " (" . ($res->success ? "PASS" : "FAIL") . ")\n";
    echo "Message:         " . $res->message . "\n";
    echo "Event UID:       " . $res->uid . "\n";
    echo "Gap Policy:      " . $res->gapPolicy . "\n";
    echo "Fold Policy:     " . $res->foldPolicy . "\n";
    echo "Nominal Count:   " . $res->nominalCount . "\n";
    echo "Resolved Count:  " . $res->resolvedCount . "\n";
    echo "Skipped Count:   " . $res->skippedCount . "\n";
    echo "Excluded Count:  " . $res->excludedCount . "\n";
    echo "--------------------------------------------------------\n";
    echo "Occurrences:\n";
    foreach ($res->occurrences as $occ) {
        $cCount = count($occ->candidateInstants);
        $instant = $occ->selectedInstant ?? 'N/A';
        $policy = $occ->appliedPolicy ?? '-';
        echo sprintf(
            "  [%02d] %s -> %s | Status: %-8s | Candidates: %d | Applied: %s\n",
            $occ->nominalOrdinal,
            $occ->nominalLabel,
            $instant,
            $occ->status,
            $cCount,
            $policy
        );
    }
    echo "========================================================\n";
}
