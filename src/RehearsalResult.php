<?php

declare(strict_types=1);

namespace Lofrano\IcalRehearsal;

/**
 * Result of executing an iCalendar transition rehearsal.
 */
final class RehearsalResult
{
    /**
     * @param Occurrence[] $occurrences
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly string $message,
        public readonly string $uid,
        public readonly string $gapPolicy,
        public readonly string $foldPolicy,
        public readonly int $nominalCount,
        public readonly int $resolvedCount,
        public readonly int $skippedCount,
        public readonly int $excludedCount,
        public readonly array $occurrences,
        public readonly array $provenance
    ) {
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'status' => $this->status,
            'message' => $this->message,
            'uid' => $this->uid,
            'gap_policy' => $this->gapPolicy,
            'fold_policy' => $this->foldPolicy,
            'nominal_count' => $this->nominalCount,
            'resolved_count' => $this->resolvedCount,
            'skipped_count' => $this->skippedCount,
            'excluded_count' => $this->excludedCount,
            'occurrences' => array_map(fn(Occurrence $o) => $o->toArray(), $this->occurrences),
            'provenance' => $this->provenance,
        ];
    }

    public function toJson(int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR);
    }
}
