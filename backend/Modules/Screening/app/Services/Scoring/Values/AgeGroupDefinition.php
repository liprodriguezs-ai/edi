<?php

namespace Modules\Screening\Services\Scoring\Values;

/**
 * One age_group row inside a {@see RuleSet} (design §3). Official groups
 * carry non-null day ranges and (except G1) an `alternateGroupCode`;
 * alternates carry null ranges and are reached ONLY via that pointer, never
 * by age lookup (instrument D8).
 */
final readonly class AgeGroupDefinition
{
    public function __construct(
        public string $code,
        public bool $isAlternate,
        public ?int $minAgeDays,
        public ?int $maxAgeDays,
        public ?string $alternateGroupCode,
        public ?int $legacyId,
    ) {}

    public function containsDay(int $chronologicalAgeDays): bool
    {
        return $this->minAgeDays !== null
            && $this->maxAgeDays !== null
            && $chronologicalAgeDays >= $this->minAgeDays
            && $chronologicalAgeDays <= $this->maxAgeDays;
    }
}
