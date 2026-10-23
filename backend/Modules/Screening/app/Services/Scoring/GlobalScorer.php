<?php

namespace Modules\Screening\Services\Scoring;

use Modules\Screening\Services\Scoring\Exceptions\IncompleteRuleSetException;
use Modules\Screening\Services\Scoring\Values\AreaResult;
use Modules\Screening\Services\Scoring\Values\ResultColorKey;
use Modules\Screening\Services\Scoring\Values\RuleSet;

/**
 * First-match-wins over the applied official group's `global_scoring_rule`
 * rows, in ascending `sequence` (design §4/D13, REQ-SCO-007). If nothing
 * matches -- impossible with the seeded catch-all, defensive anyway -- the
 * engine throws, never defaults.
 */
final class GlobalScorer
{
    public function __construct(
        private readonly ConditionEvaluator $conditionEvaluator = new ConditionEvaluator,
    ) {}

    /**
     * @param  array<int, AreaResult>  $areaResults
     * @return array{color_key: string, message_key: string, sequence: int}
     */
    public function score(
        string $appliedGroupCode,
        RuleSet $ruleSet,
        array $areaResults,
        int $alertSigns,
        int $riskFactors,
    ): array {
        $countFacts = [
            'red_areas' => 0,
            'yellow_areas' => 0,
            'green_areas' => 0,
            'alert_signs' => $alertSigns,
            'risk_factors' => $riskFactors,
        ];

        $areaColors = [];
        foreach ($areaResults as $areaResult) {
            $areaColors[$areaResult->areaCode] = $areaResult->colorKey;
            $countFacts[$this->countKeyFor($areaResult->colorKey)]++;
        }

        foreach ($ruleSet->globalRulesFor($appliedGroupCode) as $rule) {
            if ($this->conditionEvaluator->evaluate($rule->condition, $countFacts, $areaColors)) {
                return [
                    'color_key' => $rule->colorKey,
                    'message_key' => $rule->messageKey,
                    'sequence' => $rule->sequence,
                ];
            }
        }

        throw new IncompleteRuleSetException(
            "No global_scoring_rule matched for group '{$appliedGroupCode}' -- the RuleSet is missing its catch-all."
        );
    }

    private function countKeyFor(string $colorKey): string
    {
        return match ($colorKey) {
            ResultColorKey::RED => 'red_areas',
            ResultColorKey::YELLOW => 'yellow_areas',
            ResultColorKey::GREEN => 'green_areas',
            default => throw new IncompleteRuleSetException("Unknown area color key '{$colorKey}'."),
        };
    }
}
