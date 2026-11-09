<?php

declare(strict_types=1);

namespace Lofrano\IcalRehearsal;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;

final class GapException extends RuntimeException
{
}

final class UnexpectedMultiplicityException extends RuntimeException
{
}

/**
 * Resolves nominal civil recurrence labels against native DateTimeZone transitions,
 * forming explicit round-trip candidate instants and applying gap and fold policies.
 */
final class TimezoneResolver
{
    public static function resolveOccurrence(
        int $ordinal,
        string $nominalLabel,
        string $tzid,
        DateInterval $duration,
        string $gapPolicy,
        string $foldPolicy,
        bool $isExcluded
    ): Occurrence {
        if ($isExcluded) {
            return new Occurrence(
                nominalOrdinal: $ordinal,
                nominalLabel: $nominalLabel,
                tzid: $tzid,
                status: 'EXCLUDED',
                reason: 'exdate_match',
                candidateInstants: [],
                selectedInstant: null,
                durationEnd: null,
                selectedOffset: null,
                appliedPolicy: null
            );
        }

        if ($tzid === 'UTC') {
            $utc = new DateTimeZone('UTC');
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $nominalLabel, $utc);
            if ($dt === false) {
                throw new \InvalidArgumentException("Invalid nominal label '{$nominalLabel}'");
            }
            $instantStr = $dt->format(DateTimeInterface::RFC3339);
            $durationEnd = $dt->add($duration)->format(DateTimeInterface::RFC3339);

            return new Occurrence(
                nominalOrdinal: $ordinal,
                nominalLabel: $nominalLabel,
                tzid: 'UTC',
                status: 'RESOLVED',
                reason: '',
                candidateInstants: [$instantStr],
                selectedInstant: $instantStr,
                durationEnd: $durationEnd,
                selectedOffset: 0,
                appliedPolicy: 'single'
            );
        }

        $tz = new DateTimeZone($tzid);

        // Approximate unix timestamp of nominal day
        $parts = explode(' ', $nominalLabel);
        $dateParts = explode('-', $parts[0]);
        $year = (int)$dateParts[0];
        $month = (int)$dateParts[1];
        $day = (int)$dateParts[2];

        $middayTimestamp = (int)gmmktime(12, 0, 0, $month, $day, $year);
        $rangeStart = $middayTimestamp - 86400 * 2;
        $rangeEnd = $middayTimestamp + 86400 * 2;

        $transitions = $tz->getTransitions($rangeStart, $rangeEnd);
        $offsets = [];
        if (is_array($transitions)) {
            foreach ($transitions as $tr) {
                $offsets[$tr['offset']] = true;
            }
        }
        if (empty($offsets)) {
            // Fallback to offset at midday
            $probe = new DateTimeImmutable("@{$middayTimestamp}");
            $offsets[$tz->getOffset($probe)] = true;
        }

        /** @var DateTimeImmutable[] $candidates */
        $candidates = [];

        foreach (array_keys($offsets) as $off) {
            $sign = $off >= 0 ? '+' : '-';
            $abs = abs($off);
            $h = (int)floor($abs / 3600);
            $m = (int)floor(($abs % 3600) / 60);
            $offsetString = sprintf('%s%02d:%02d', $sign, $h, $m);

            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s P', "{$nominalLabel} {$offsetString}");
            if ($dt === false) {
                continue;
            }

            $inTz = $dt->setTimezone($tz);
            if ($inTz->format('Y-m-d H:i:s') === $nominalLabel && $inTz->getOffset() === $off) {
                $candidates[] = $inTz;
            }
        }

        // Deduplicate and sort by UTC instant ascending
        usort($candidates, fn(DateTimeImmutable $a, DateTimeImmutable $b) => $a->getTimestamp() <=> $b->getTimestamp());
        $uniqueCandidates = [];
        $seenTimestamps = [];
        foreach ($candidates as $c) {
            if (!isset($seenTimestamps[$c->getTimestamp()])) {
                $seenTimestamps[$c->getTimestamp()] = true;
                $uniqueCandidates[] = $c;
            }
        }

        $candidateStrings = array_map(fn(DateTimeImmutable $d) => $d->format(DateTimeInterface::RFC3339), $uniqueCandidates);
        $numCandidates = count($uniqueCandidates);

        if ($numCandidates === 0) {
            // DST Gap
            if ($gapPolicy === 'reject-series') {
                throw new GapException("Nominal civil label '{$nominalLabel}' does not exist in timezone '{$tzid}' (DST spring gap)");
            } elseif ($gapPolicy === 'skip-instance') {
                return new Occurrence(
                    nominalOrdinal: $ordinal,
                    nominalLabel: $nominalLabel,
                    tzid: $tzid,
                    status: 'SKIPPED',
                    reason: 'dst_gap',
                    candidateInstants: [],
                    selectedInstant: null,
                    durationEnd: null,
                    selectedOffset: null,
                    appliedPolicy: null
                );
            }
            throw new \InvalidArgumentException("Unknown gap policy: {$gapPolicy}");
        }

        if ($numCandidates === 1) {
            $selected = $uniqueCandidates[0];
            $selectedStr = $selected->format(DateTimeInterface::RFC3339);
            $durationEnd = $selected->add($duration)->format(DateTimeInterface::RFC3339);

            return new Occurrence(
                nominalOrdinal: $ordinal,
                nominalLabel: $nominalLabel,
                tzid: $tzid,
                status: 'RESOLVED',
                reason: '',
                candidateInstants: $candidateStrings,
                selectedInstant: $selectedStr,
                durationEnd: $durationEnd,
                selectedOffset: $selected->getOffset(),
                appliedPolicy: 'single'
            );
        }

        if ($numCandidates === 2) {
            // DST Fold
            if ($foldPolicy === 'earlier-instant') {
                $selected = $uniqueCandidates[0];
            } elseif ($foldPolicy === 'later-instant') {
                $selected = $uniqueCandidates[1];
            } else {
                throw new \InvalidArgumentException("Unknown fold policy: {$foldPolicy}");
            }

            $selectedStr = $selected->format(DateTimeInterface::RFC3339);
            $durationEnd = $selected->add($duration)->format(DateTimeInterface::RFC3339);

            return new Occurrence(
                nominalOrdinal: $ordinal,
                nominalLabel: $nominalLabel,
                tzid: $tzid,
                status: 'RESOLVED',
                reason: '',
                candidateInstants: $candidateStrings,
                selectedInstant: $selectedStr,
                durationEnd: $durationEnd,
                selectedOffset: $selected->getOffset(),
                appliedPolicy: $foldPolicy
            );
        }

        throw new UnexpectedMultiplicityException("Unexpected candidate multiplicity ({$numCandidates}) for '{$nominalLabel}' in '{$tzid}'");
    }
}
