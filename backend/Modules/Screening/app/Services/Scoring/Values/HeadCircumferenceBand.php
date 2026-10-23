<?php

namespace Modules\Screening\Services\Scoring\Values;

/**
 * One head_circumference_reference row inside a {@see RuleSet} (design
 * §3/D9): the plausible +-2 SD band, in integer millimeters, for a sex and
 * age-in-months bucket.
 */
final readonly class HeadCircumferenceBand
{
    public function __construct(
        public string $sexKey,
        public int $ageMonths,
        public int $minMm,
        public int $maxMm,
    ) {}

    public function contains(int $measurementMm): bool
    {
        return $measurementMm >= $this->minMm && $measurementMm <= $this->maxMm;
    }
}
