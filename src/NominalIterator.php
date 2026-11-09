<?php

declare(strict_types=1);

namespace Lofrano\IcalRehearsal;

use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Recur\RRuleIterator;

/**
 * Stepper that generates nominal civil recurrence labels using Sabre VObject's
 * RRuleIterator with a documented UTC surrogate to prevent premature DST shifting.
 */
final class NominalIterator
{
    /**
     * @return string[] Array of nominal civil date-time strings "YYYY-MM-DD HH:MM:SS"
     */
    public static function generateNominalLabels(string $startNominalLabel, string $rruleString, int $maxCount): array
    {
        $utc = new DateTimeZone('UTC');
        $surrogateStart = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startNominalLabel, $utc);
        if ($surrogateStart === false) {
            throw new \InvalidArgumentException("Invalid nominal start label format: {$startNominalLabel}");
        }

        $iterator = new RRuleIterator($rruleString, $surrogateStart);

        $labels = [];
        $count = 0;
        foreach ($iterator as $occurrence) {
            $labels[] = $occurrence->format('Y-m-d H:i:s');
            $count++;
            if ($count >= $maxCount) {
                break;
            }
        }

        return $labels;
    }
}
