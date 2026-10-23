<?php

namespace Modules\Screening\Services\Scoring;

use Modules\Screening\Services\Scoring\Values\AreaResult;
use Modules\Screening\Services\Scoring\Values\ItemAnswer;
use Modules\Screening\Services\Scoring\Values\ResultColorKey;
use Modules\Screening\Services\Scoring\Values\RuleSet;
use Modules\Screening\Services\Scoring\Values\ScoringFinding;

/**
 * Model A pooling (instrument design §14, design D4 -- REQ-SCO-005): for
 * one semaphore area of the applied OFFICIAL group, pools the answered
 * items of the group's OWN area items with the answered items of the SAME
 * area in the group's ALTERNATE, then applies the OFFICIAL group's
 * `area_scoring_rule` 4-branch contract. The alternate group's own
 * `area_scoring_rule` row is NEVER consulted -- it is loaded into
 * `RuleSet::$alternateAreaRules` as record only and this class never reads
 * that map.
 */
final class AreaScorer
{
    /**
     * @param  array<int, ItemAnswer>  $answers
     * @return array{0: AreaResult, 1: array<int, ScoringFinding>}
     */
    public function score(string $appliedGroupCode, string $areaCode, RuleSet $ruleSet, array $answers): array
    {
        $group = $ruleSet->group($appliedGroupCode);
        $rule = $ruleSet->areaRule($appliedGroupCode, $areaCode);

        $answerLabelByGroupItem = [];
        foreach ($answers as $answer) {
            $answerLabelByGroupItem["{$answer->ageGroupCode}/{$answer->itemCode}"] = $answer->selectedLabel;
        }

        $counts = [ResultColorKey::RED => 0, ResultColorKey::YELLOW => 0, ResultColorKey::GREEN => 0];
        $findings = [];

        $answeredOwn = 0;
        foreach ($ruleSet->itemsInGroupArea($appliedGroupCode, $areaCode) as $item) {
            $label = $answerLabelByGroupItem["{$item->groupCode}/{$item->itemCode}"] ?? null;

            if ($label === null) {
                $findings[] = new ScoringFinding(ScoringFinding::UNANSWERED_ITEM, [
                    'group_code' => $item->groupCode,
                    'item_code' => $item->itemCode,
                ]);

                continue;
            }

            $answeredOwn++;
            $counts[$item->colorForLabel($label)]++;
        }

        $answeredAlternate = 0;
        if ($group->alternateGroupCode !== null) {
            foreach ($ruleSet->itemsInGroupArea($group->alternateGroupCode, $areaCode) as $item) {
                $label = $answerLabelByGroupItem["{$item->groupCode}/{$item->itemCode}"] ?? null;

                if ($label === null) {
                    // Alternate items are only administered when the axis needs
                    // evaluación alterna -- an unanswered alternate is the
                    // routine case, not a finding-worthy gap (design §14).
                    continue;
                }

                $answeredAlternate++;
                $counts[$item->colorForLabel($label)]++;
            }
        }

        $colorKey = $rule->colorFor($counts[ResultColorKey::RED], $counts[ResultColorKey::YELLOW], $counts[ResultColorKey::GREEN]);

        $result = new AreaResult(
            areaCode: $areaCode,
            colorKey: $colorKey,
            reds: $counts[ResultColorKey::RED],
            yellows: $counts[ResultColorKey::YELLOW],
            greens: $counts[ResultColorKey::GREEN],
            answeredOwn: $answeredOwn,
            answeredAlternate: $answeredAlternate,
            redThreshold: $rule->redThreshold,
            greenThreshold: $rule->greenThreshold,
            allowsYellow: $rule->allowsYellow,
        );

        return [$result, $findings];
    }
}
