<?php

namespace Modules\Screening\Services\Scoring;

use Modules\Screening\Services\Scoring\Values\ItemAnswer;
use Modules\Screening\Services\Scoring\Values\ResultColorKey;
use Modules\Screening\Services\Scoring\Values\RuleSet;

/**
 * `alert_signs` and `risk_factors` (design §3, REQ-SCO-006): integer
 * counters, never semaphores, that feed both the result payload and the
 * global grammar vocabulary.
 */
final class CounterScorer
{
    /**
     * @param  array<int, ItemAnswer>  $answers
     */
    public function alertSigns(string $appliedGroupCode, RuleSet $ruleSet, array $answers): int
    {
        $labelByItem = [];
        foreach ($answers as $answer) {
            if ($answer->ageGroupCode === $appliedGroupCode) {
                $labelByItem[$answer->itemCode] = $answer->selectedLabel;
            }
        }

        $count = 0;
        foreach ($ruleSet->itemsInGroupArea($appliedGroupCode, RuleSet::ALE_AREA_CODE) as $item) {
            $label = $labelByItem[$item->itemCode] ?? null;
            if ($label !== null && $item->colorForLabel($label) === ResultColorKey::YELLOW) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, bool|null>  $riskFactorAnswers  FRB code => yes/no/unknown
     */
    public function riskFactors(array $riskFactorAnswers): int
    {
        return count(array_filter($riskFactorAnswers, fn (?bool $answer) => $answer === true));
    }
}
