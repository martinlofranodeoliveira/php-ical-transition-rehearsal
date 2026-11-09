<?php

declare(strict_types=1);

namespace Lofrano\IcalRehearsal;

use DateInterval;
use InvalidArgumentException;
use Sabre\VObject;

/**
 * Strict parser for bounded single-event iCalendar documents.
 */
final class IcsParser
{
    public const MAX_INPUT_BYTES = 65536;
    public const MAX_COUNT = 100;

    public const ALLOWED_TZIDS = [
        'UTC',
        'America/New_York',
        'America/Sao_Paulo',
        'Europe/London',
        'Europe/Paris',
        'Australia/Lord_Howe',
        'Asia/Tokyo',
        'America/Los_Angeles',
        'America/Chicago',
    ];

    public static function parse(string $rawIcs): ParsedEvent
    {
        if (strlen($rawIcs) > self::MAX_INPUT_BYTES) {
            throw new InvalidArgumentException('ICS payload exceeds maximum size limit of ' . self::MAX_INPUT_BYTES . ' bytes');
        }

        if (trim($rawIcs) === '') {
            throw new InvalidArgumentException('ICS payload is empty');
        }

        try {
            $vcal = VObject\Reader::read($rawIcs);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Malformed iCalendar format: ' . $e->getMessage(), 0, $e);
        }

        if (!isset($vcal->VEVENT)) {
            throw new InvalidArgumentException('iCalendar document must contain a VEVENT component');
        }

        if (count($vcal->select('VEVENT')) > 1) {
            throw new InvalidArgumentException('Recurrence overrides with multiple VEVENTs are unsupported');
        }

        if (isset($vcal->VTIMEZONE)) {
            throw new InvalidArgumentException('Custom VTIMEZONE definitions are unsupported; use qualified IANA TZIDs');
        }

        $event = $vcal->VEVENT;

        // 1. UID
        $uid = (string)$event->UID;
        if (trim($uid) === '') {
            throw new InvalidArgumentException('VEVENT must contain a non-empty UID');
        }

        // 2. DTSTART
        if (!isset($event->DTSTART)) {
            throw new InvalidArgumentException('VEVENT must contain a DTSTART property');
        }

        $dtstartProp = $event->DTSTART;
        $dtstartRaw = (string)$dtstartProp;
        $tzidParam = $dtstartProp['TZID'] ? (string)$dtstartProp['TZID'] : null;

        $isUtc = str_ends_with($dtstartRaw, 'Z');
        if ($isUtc) {
            $tzid = 'UTC';
            $localDigits = substr($dtstartRaw, 0, -1);
        } elseif ($tzidParam !== null) {
            $tzid = $tzidParam;
            $localDigits = $dtstartRaw;
        } else {
            throw new InvalidArgumentException('Floating local times without UTC Z or TZID parameter are rejected');
        }

        if (!in_array($tzid, self::ALLOWED_TZIDS, true)) {
            throw new InvalidArgumentException("Timezone TZID '{$tzid}' is outside the qualified allowlist");
        }

        // Parse local civil date and time digits: e.g. "20260308T023000"
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})$/', $localDigits, $m)) {
            throw new InvalidArgumentException("DTSTART format '{$dtstartRaw}' is invalid (expected YYYYMMDDTHHMMSS)");
        }
        $startNominalLabel = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);

        // 3. DURATION
        if (!isset($event->DURATION)) {
            throw new InvalidArgumentException('VEVENT must contain an explicit elapsed DURATION property');
        }
        $durationRaw = (string)$event->DURATION;
        try {
            $duration = new DateInterval($durationRaw);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException("Invalid DURATION property '{$durationRaw}': " . $e->getMessage());
        }

        // Verify positive duration <= 7 days
        if ($duration->invert === 1 || ($duration->y === 0 && $duration->m === 0 && $duration->d === 0 && $duration->h === 0 && $duration->i === 0 && $duration->s === 0)) {
            throw new InvalidArgumentException('DURATION must be strictly positive');
        }
        if ($duration->y > 0 || $duration->m > 0 || $duration->d > 7) {
            throw new InvalidArgumentException('DURATION exceeds maximum allowed bound of 7 days');
        }

        // 4. RRULE
        if (!isset($event->RRULE)) {
            throw new InvalidArgumentException('VEVENT must contain an RRULE property');
        }
        $rruleProp = $event->RRULE;
        $rruleMap = [];
        if (method_exists($rruleProp, 'getParts')) {
            $rruleMap = $rruleProp->getParts();
        } else {
            foreach (explode(';', (string)$rruleProp) as $part) {
                if (str_contains($part, '=')) {
                    [$k, $v] = explode('=', $part, 2);
                    $rruleMap[strtoupper(trim($k))] = trim($v);
                }
            }
        }

        // Reject forbidden RRULE properties
        $forbidden = ['UNTIL', 'RDATE', 'BYSETPOS', 'BYSECOND', 'BYMINUTE', 'BYHOUR', 'BYMONTHDAY', 'BYYEARDAY', 'BYWEEKNO', 'BYMONTH'];
        foreach ($forbidden as $f) {
            if (isset($rruleMap[$f]) || isset($event->$f)) {
                throw new InvalidArgumentException("Recurrence property '{$f}' is unsupported in the restricted profile");
            }
        }

        $freq = strtoupper((string)($rruleMap['FREQ'] ?? ''));
        if (!in_array($freq, ['DAILY', 'WEEKLY'], true)) {
            throw new InvalidArgumentException("FREQ '{$freq}' is unsupported; only DAILY and WEEKLY are permitted");
        }

        $interval = isset($rruleMap['INTERVAL']) ? (int)$rruleMap['INTERVAL'] : 1;
        if ($interval <= 0) {
            throw new InvalidArgumentException('INTERVAL must be a positive integer');
        }

        if (!isset($rruleMap['COUNT'])) {
            throw new InvalidArgumentException('Mandatory COUNT parameter is missing from RRULE');
        }
        $count = (int)$rruleMap['COUNT'];
        if ($count <= 0 || $count > self::MAX_COUNT) {
            throw new InvalidArgumentException("COUNT must be an integer between 1 and " . self::MAX_COUNT);
        }

        $byDay = $rruleMap['BYDAY'] ?? null;
        if ($byDay !== null) {
            if ($freq !== 'WEEKLY') {
                throw new InvalidArgumentException('BYDAY is only supported for WEEKLY recurrence');
            }
            // Check non-numeric
            $days = is_array($byDay) ? $byDay : explode(',', (string)$byDay);
            $validDays = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];
            foreach ($days as $day) {
                if (!in_array(strtoupper(trim($day)), $validDays, true)) {
                    throw new InvalidArgumentException("Invalid or numeric BYDAY token '{$day}'");
                }
            }
        }

        // 5. EXDATE
        $exdates = [];
        if (isset($event->EXDATE)) {
            foreach ($event->select('EXDATE') as $exdateProp) {
                $rawVal = (string)$exdateProp;
                foreach (explode(',', $rawVal) as $singleEx) {
                    $cleanEx = trim($singleEx);
                    if ($cleanEx === '') continue;
                    $cleanExDigits = str_ends_with($cleanEx, 'Z') ? substr($cleanEx, 0, -1) : $cleanEx;
                    if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})$/', $cleanExDigits, $em)) {
                        $exdates[] = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $em[1], $em[2], $em[3], $em[4], $em[5], $em[6]);
                    }
                }
            }
        }

        $rruleString = (string)$rruleProp;

        return new ParsedEvent(
            uid: $uid,
            tzid: $tzid,
            startNominalLabel: $startNominalLabel,
            duration: $duration,
            durationString: $durationRaw,
            rruleString: $rruleString,
            count: $count,
            freq: $freq,
            interval: $interval,
            exdates: array_unique($exdates)
        );
    }
}

final class ParsedEvent
{
    public function __construct(
        public readonly string $uid,
        public readonly string $tzid,
        public readonly string $startNominalLabel,
        public readonly DateInterval $duration,
        public readonly string $durationString,
        public readonly string $rruleString,
        public readonly int $count,
        public readonly string $freq,
        public readonly int $interval,
        public readonly array $exdates
    ) {
    }
}
