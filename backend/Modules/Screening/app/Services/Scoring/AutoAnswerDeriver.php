<?php

namespace Modules\Screening\Services\Scoring;

use Modules\Screening\Services\Scoring\Values\DerivedAnswer;
use Modules\Screening\Services\Scoring\Values\ItemAnswer;
use Modules\Screening\Services\Scoring\Values\ResultColorKey;
use Modules\Screening\Services\Scoring\Values\RuleSet;
use Modules\Screening\Services\Scoring\Values\ScoringFinding;
use Modules\Screening\Services\Scoring\Values\ScoringInput;

/**
 * Auto-derives the three `auto_derivation_key` risk factors and the
 * head-circumference item answer from perinatal input (design D8/D9/D10,
 * REQ-SCO-004). A NULL perinatal value derives NOTHING and is never treated
 * as "no" -- the answer stays manually answerable and a
 * `unknown_perinatal_input` finding is recorded (clinic decision D-14).
 * A known value derives BOTH ways and OVERRIDES a manual answer, recording
 * `auto_answer_overrode_manual` only when it actually changed one.
 */
final class AutoAnswerDeriver
{
    private const GESTATIONAL_AGE_KEY = 'gestational_age_lt_34_weeks';

    private const BIRTH_WEIGHT_KEY = 'birth_weight_lte_1500_g';

    private const MOTHER_AGE_KEY = 'mother_age_lt_16_years';

    private const GESTATIONAL_AGE_THRESHOLD_WEEKS = 34;

    private const BIRTH_WEIGHT_THRESHOLD_GRAMS = 1500;

    private const MOTHER_AGE_THRESHOLD_YEARS = 16;

    private const AGE_DAYS_PER_MONTH = 30;

    /**
     * @return array{
     *     risk_factor_answers: array<string, bool|null>,
     *     head_circumference_answer: ?ItemAnswer,
     *     derived_answers: array<int, DerivedAnswer>,
     *     findings: array<int, ScoringFinding>,
     * }
     */
    public function derive(
        ScoringInput $input,
        RuleSet $ruleSet,
        string $appliedGroupCode,
        ?int $chronologicalAgeDays,
    ): array {
        $findings = [];
        $derivedAnswers = [];
        $riskFactorAnswers = $input->riskFactorAnswers;

        foreach ($this->deriveRiskFactors($input, $ruleSet) as $derivation) {
            [$code, $derived, $sourceField] = $derivation;

            if ($derived === null) {
                $findings[] = new ScoringFinding(ScoringFinding::UNKNOWN_PERINATAL_INPUT, [
                    'risk_factor_code' => $code,
                    'source_field' => $sourceField,
                ]);

                continue;
            }

            $manual = $riskFactorAnswers[$code] ?? null;
            if ($manual !== null && $manual !== $derived) {
                $findings[] = new ScoringFinding(ScoringFinding::AUTO_ANSWER_OVERRODE_MANUAL, [
                    'risk_factor_code' => $code,
                    'manual_value' => $manual,
                    'derived_value' => $derived,
                ]);
            }

            $riskFactorAnswers[$code] = $derived;
            $derivedAnswers[] = new DerivedAnswer(DerivedAnswer::KIND_RISK_FACTOR, $code, $derived, $sourceField);
        }

        [$headCircumferenceAnswer, $hcFindings, $hcDerived] = $this->deriveHeadCircumference(
            $input,
            $ruleSet,
            $appliedGroupCode,
            $chronologicalAgeDays,
        );

        return [
            'risk_factor_answers' => $riskFactorAnswers,
            'head_circumference_answer' => $headCircumferenceAnswer,
            'derived_answers' => [...$derivedAnswers, ...$hcDerived],
            'findings' => [...$findings, ...$hcFindings],
        ];
    }

    /**
     * @return array<int, array{0: string, 1: ?bool, 2: string}> [risk_factor_code, derived_yes_no_or_null, source_field]
     */
    private function deriveRiskFactors(ScoringInput $input, RuleSet $ruleSet): array
    {
        $derivations = [];

        foreach ([
            self::GESTATIONAL_AGE_KEY => ['gestational_age_weeks', $input->gestationalAgeWeeks, self::GESTATIONAL_AGE_THRESHOLD_WEEKS, '<'],
            self::BIRTH_WEIGHT_KEY => ['birth_weight_grams', $input->birthWeightGrams, self::BIRTH_WEIGHT_THRESHOLD_GRAMS, '<='],
            self::MOTHER_AGE_KEY => ['mother_age_at_birth_years', $input->motherAgeAtBirthYears, self::MOTHER_AGE_THRESHOLD_YEARS, '<'],
        ] as $autoDerivationKey => [$sourceField, $value, $threshold, $comparator]) {
            $factor = $ruleSet->riskFactorByAutoDerivationKey($autoDerivationKey);
            if ($factor === null) {
                continue;
            }

            $derived = $value === null ? null : ($comparator === '<' ? $value < $threshold : $value <= $threshold);

            $derivations[] = [$factor->code, $derived, $sourceField];
        }

        return $derivations;
    }

    /**
     * @return array{0: ?ItemAnswer, 1: array<int, ScoringFinding>, 2: array<int, DerivedAnswer>}
     */
    private function deriveHeadCircumference(
        ScoringInput $input,
        RuleSet $ruleSet,
        string $appliedGroupCode,
        ?int $chronologicalAgeDays,
    ): array {
        $itemCode = $ruleSet->headCircumferenceItemCodeFor($appliedGroupCode);
        if ($itemCode === null) {
            // G15 (and any group without a designated item): nothing to derive.
            return [null, [], []];
        }

        if ($input->headCircumferenceMm === null) {
            $finding = new ScoringFinding(ScoringFinding::UNKNOWN_PERINATAL_INPUT, [
                'item_code' => $itemCode,
                'group_code' => $appliedGroupCode,
                'source_field' => 'head_circumference_mm',
            ]);

            return [null, [$finding], []];
        }

        if ($chronologicalAgeDays === null || $input->sexKey === null) {
            // No resolved age or sex to look a band up with (e.g. the
            // forGroup/replay path never carries a measurement anyway --
            // design D15). Nothing safe to derive.
            return [null, [], []];
        }

        $ageMonths = intdiv($chronologicalAgeDays, self::AGE_DAYS_PER_MONTH);
        $band = $ruleSet->headCircumferenceBand($input->sexKey, $ageMonths);

        if ($band === null) {
            $finding = new ScoringFinding(ScoringFinding::MISSING_PERCENTILE_REFERENCE, [
                'item_code' => $itemCode,
                'group_code' => $appliedGroupCode,
                'sex_key' => $input->sexKey,
                'age_months' => $ageMonths,
            ]);

            return [null, [$finding], []];
        }

        $item = $ruleSet->item($appliedGroupCode, $itemCode);
        $colorKey = $band->contains($input->headCircumferenceMm) ? ResultColorKey::GREEN : ResultColorKey::RED;
        $label = $item->labelForColor($colorKey);

        $findings = [];
        $manualLabel = $this->manualLabelFor($input->answers, $appliedGroupCode, $itemCode);
        if ($manualLabel !== null && $manualLabel !== $label) {
            $findings[] = new ScoringFinding(ScoringFinding::AUTO_ANSWER_OVERRODE_MANUAL, [
                'item_code' => $itemCode,
                'group_code' => $appliedGroupCode,
                'manual_label' => $manualLabel,
                'derived_label' => $label,
            ]);
        }

        $answer = new ItemAnswer($appliedGroupCode, $itemCode, $label);
        $derived = new DerivedAnswer(DerivedAnswer::KIND_HEAD_CIRCUMFERENCE_ITEM, $itemCode, $label, 'head_circumference_mm');

        return [$answer, $findings, [$derived]];
    }

    /**
     * @param  array<int, ItemAnswer>  $answers
     */
    private function manualLabelFor(array $answers, string $groupCode, string $itemCode): ?string
    {
        foreach ($answers as $answer) {
            if ($answer->ageGroupCode === $groupCode && $answer->itemCode === $itemCode) {
                return $answer->selectedLabel;
            }
        }

        return null;
    }
}
