<?php

namespace Modules\Screening\Services\Scoring\Values;

/**
 * One global_scoring_rule row inside a {@see RuleSet} (design §3/D13).
 * `condition` is the closed 6-operator grammar (instrument design §5.4),
 * decoded to a plain PHP array -- `ConditionEvaluator` interprets it, never
 * `eval`.
 */
final readonly class GlobalScoringRuleDefinition
{
    /**
     * @param  array<string, mixed>  $condition
     */
    public function __construct(
        public string $groupCode,
        public int $sequence,
        public array $condition,
        public string $colorKey,
        public string $messageKey,
    ) {}
}
