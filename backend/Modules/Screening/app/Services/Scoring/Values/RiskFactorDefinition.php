<?php

namespace Modules\Screening\Services\Scoring\Values;

/**
 * One risk_factor (FRB) row inside a {@see RuleSet} (design §3). Three
 * carry an `autoDerivationKey` (design D8/D9) that `AutoAnswerDeriver`
 * matches against the perinatal input on {@see ScoringInput}.
 */
final readonly class RiskFactorDefinition
{
    public function __construct(
        public string $code,
        public ?string $autoDerivationKey,
        public ?int $legacyId,
    ) {}
}
