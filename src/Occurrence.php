<?php

declare(strict_types=1);

namespace Lofrano\IcalRehearsal;

use DateTimeInterface;

/**
 * Represents a single nominal recurrence occurrence and its resolved timezone outcome.
 */
final class Occurrence
{
    public function __construct(
        public readonly int $nominalOrdinal,
        public readonly string $nominalLabel,
        public readonly string $tzid,
        public readonly string $status,
        public readonly string $reason,
        public readonly array $candidateInstants,
        public readonly ?string $selectedInstant,
        public readonly ?string $durationEnd,
        public readonly ?int $selectedOffset,
        public readonly ?string $appliedPolicy
    ) {
    }

    public function toArray(): array
    {
        return [
            'nominal_ordinal' => $this->nominalOrdinal,
            'nominal_label' => $this->nominalLabel,
            'tzid' => $this->tzid,
            'status' => $this->status,
            'reason' => $this->reason,
            'candidate_instants' => $this->candidateInstants,
            'selected_instant' => $this->selectedInstant,
            'duration_end' => $this->durationEnd,
            'selected_offset_seconds' => $this->selectedOffset,
            'applied_policy' => $this->appliedPolicy,
        ];
    }
}
