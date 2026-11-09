<?php

declare(strict_types=1);

namespace Lofrano\IcalRehearsal;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Sabre\VObject\Version;

/**
 * Main coordinator for parsing, stepping, and resolving iCalendar recurrence
 * occurrences across timezone transitions.
 */
final class IcalRehearsal
{
    /**
     * Rehearse recurrence from an ICS string.
     */
    public static function rehearse(
        string $rawIcs,
        string $gapPolicy = 'reject-series',
        string $foldPolicy = 'earlier-instant'
    ): RehearsalResult {
        if (!in_array($gapPolicy, ['reject-series', 'skip-instance'], true)) {
            throw new InvalidArgumentException("Invalid gap policy '{$gapPolicy}'. Allowed: 'reject-series', 'skip-instance'");
        }
        if (!in_array($foldPolicy, ['earlier-instant', 'later-instant'], true)) {
            throw new InvalidArgumentException("Invalid fold policy '{$foldPolicy}'. Allowed: 'earlier-instant', 'later-instant'");
        }

        try {
            $event = IcsParser::parse($rawIcs);
        } catch (InvalidArgumentException $e) {
            return new RehearsalResult(
                success: false,
                status: 'INVALID_ICS',
                message: $e->getMessage(),
                uid: '',
                gapPolicy: $gapPolicy,
                foldPolicy: $foldPolicy,
                nominalCount: 0,
                resolvedCount: 0,
                skippedCount: 0,
                excludedCount: 0,
                occurrences: [],
                provenance: self::buildProvenance('UNKNOWN', 'UNKNOWN', 'UNKNOWN')
            );
        }

        $provenance = self::buildProvenance($event->tzid, $event->rruleString, $event->durationString);

        // Generate nominal civil labels using UTC surrogate stepper
        try {
            $nominalLabels = NominalIterator::generateNominalLabels(
                $event->startNominalLabel,
                $event->rruleString,
                $event->count
            );
        } catch (\Throwable $e) {
            return new RehearsalResult(
                success: false,
                status: 'ITERATION_FAILED',
                message: 'Failed to generate nominal occurrences: ' . $e->getMessage(),
                uid: $event->uid,
                gapPolicy: $gapPolicy,
                foldPolicy: $foldPolicy,
                nominalCount: 0,
                resolvedCount: 0,
                skippedCount: 0,
                excludedCount: 0,
                occurrences: [],
                provenance: $provenance
            );
        }

        $exdateLookup = array_flip($event->exdates);

        /** @var Occurrence[] $occurrences */
        $occurrences = [];
        $resolvedCount = 0;
        $skippedCount = 0;
        $excludedCount = 0;

        foreach ($nominalLabels as $index => $nominalLabel) {
            $ordinal = $index + 1;
            $isExcluded = isset($exdateLookup[$nominalLabel]);

            try {
                $occurrence = TimezoneResolver::resolveOccurrence(
                    ordinal: $ordinal,
                    nominalLabel: $nominalLabel,
                    tzid: $event->tzid,
                    duration: $event->duration,
                    gapPolicy: $gapPolicy,
                    foldPolicy: $foldPolicy,
                    isExcluded: $isExcluded
                );
            } catch (GapException $ge) {
                // Whole series rejected due to gap under reject-series
                return new RehearsalResult(
                    success: false,
                    status: 'GAP_REJECTED',
                    message: $ge->getMessage(),
                    uid: $event->uid,
                    gapPolicy: $gapPolicy,
                    foldPolicy: $foldPolicy,
                    nominalCount: count($nominalLabels),
                    resolvedCount: $resolvedCount,
                    skippedCount: $skippedCount,
                    excludedCount: $excludedCount,
                    occurrences: $occurrences,
                    provenance: $provenance
                );
            }

            $occurrences[] = $occurrence;
            if ($occurrence->status === 'RESOLVED') {
                $resolvedCount++;
            } elseif ($occurrence->status === 'SKIPPED') {
                $skippedCount++;
            } elseif ($occurrence->status === 'EXCLUDED') {
                $excludedCount++;
            }
        }

        return new RehearsalResult(
            success: true,
            status: 'SUCCESS',
            message: 'All occurrences successfully evaluated and resolved.',
            uid: $event->uid,
            gapPolicy: $gapPolicy,
            foldPolicy: $foldPolicy,
            nominalCount: count($nominalLabels),
            resolvedCount: $resolvedCount,
            skippedCount: $skippedCount,
            excludedCount: $excludedCount,
            occurrences: $occurrences,
            provenance: $provenance
        );
    }

    /**
     * Rehearse recurrence from a file path.
     */
    public static function rehearseFile(
        string $filePath,
        string $gapPolicy = 'reject-series',
        string $foldPolicy = 'earlier-instant'
    ): RehearsalResult {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("File not found: {$filePath}");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new InvalidArgumentException("Failed to read file: {$filePath}");
        }

        return self::rehearse($content, $gapPolicy, $foldPolicy);
    }

    private static function buildProvenance(string $tzid, string $rrule, string $duration): array
    {
        $vobjectVersion = class_exists(Version::class) ? Version::VERSION : '4.6.1';
        $nowUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::RFC3339);

        return [
            'php_version' => PHP_VERSION,
            'vobject_version' => $vobjectVersion,
            'timezone_version' => timezone_version_get(),
            'evaluation_time_utc' => $nowUtc,
            'tzid' => $tzid,
            'rrule' => $rrule,
            'duration' => $duration,
        ];
    }
}
