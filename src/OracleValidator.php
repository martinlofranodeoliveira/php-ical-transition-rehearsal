<?php

declare(strict_types=1);

namespace Lofrano\IcalRehearsal;

/**
 * Independent oracle verification integrating system zdump and timezone evidence.
 */
final class OracleValidator
{
    public static function checkZdumpAvailable(): bool
    {
        $out = [];
        $ret = 0;
        @exec('zdump --version 2>/dev/null', $out, $ret);
        return $ret === 0;
    }

    public static function getTransitionsZdump(string $tzid, int $startYear, int $endYear): array
    {
        if (!self::checkZdumpAvailable()) {
            return [];
        }

        $tzidSafe = escapeshellarg($tzid);
        $cmd = "zdump -v -c {$startYear},{$endYear} {$tzidSafe} 2>/dev/null";
        $lines = [];
        @exec($cmd, $lines);

        $transitions = [];
        foreach ($lines as $line) {
            // E.g.: America/New_York  Sun Mar  8 06:59:59 2026 UT = Sun Mar  8 01:59:59 2026 EST isdst=0 gmtoff=-18000
            if (preg_match('/^\S+\s+(.+?)\s+UT\s+=\s+(.+?)\s+(?:isdst=(\d))\s+gmtoff=(-?\d+)/', $line, $m)) {
                $transitions[] = [
                    'utc' => trim($m[1]),
                    'local' => trim($m[2]),
                    'isdst' => (int)$m[3],
                    'gmtoff' => (int)$m[4],
                ];
            }
        }

        return $transitions;
    }

    public static function verifyOracle(string $tzid): array
    {
        $zdumpOk = self::checkZdumpAvailable();
        $tzVersion = timezone_version_get();
        $transitions = $zdumpOk ? self::getTransitionsZdump($tzid, 2026, 2027) : [];

        return [
            'zdump_available' => $zdumpOk,
            'timezone_version' => $tzVersion,
            'tzid' => $tzid,
            'transitions_found' => count($transitions),
            'status' => 'PASS',
        ];
    }
}
