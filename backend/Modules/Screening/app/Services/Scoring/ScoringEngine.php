<?php

namespace Modules\Screening\Services\Scoring;

use Modules\Screening\Services\Scoring\Exceptions\InvalidScoringInputException;
use Modules\Screening\Services\Scoring\Values\ItemAnswer;
use Modules\Screening\Services\Scoring\Values\RuleSet;
use Modules\Screening\Services\Scoring\Values\ScoringInput;
use Modules\Screening\Services\Scoring\Values\ScoringResult;

/**
 * Pure function: `score(ScoringInput, RuleSet) -> ScoringResult`
 * (REQ-SCO-001). Every collaborator is a stateless class, composed here via
 * constructor injection with `new ScoringEngine()` default wiring -- no
 * container needed, including inside a plain `PHPUnit\Framework\TestCase`
 * (the oracle harness).
 *
 * Pipeline (design §4):
 * 1. AgeResolver          dates -> chronological/corrected days (skipped on the forGroup path)
 * 2. AgeGroupResolver     days -> applied official group (+ substitution finding)
 * 3. AutoAnswerDeriver    perinatal input + RuleSet -> derived FRB/HC answers, merged over manual
 * 4. AreaScorer           per semaphore area: pooled own+alternate counts -> 4-branch contract
 * 5. CounterScorer        alert_signs + risk_factors
 * 6. GlobalScorer         first-match over global rules, via ConditionEvaluator
 * 7. SituationResolver    previousResultColorKey + global color -> SituationOutcome
 * 8. assemble             ScoringResult with trace + findings
 */
final class ScoringEngine
{
    public function __construct(
        private readonly AgeResolver $ageResolver = new AgeResolver,
        private readonly AgeGroupResolver $ageGroupResolver = new AgeGroupResolver,
        private readonly AutoAnswerDeriver $autoAnswerDeriver = new AutoAnswerDeriver,
        private readonly AreaScorer $areaScorer = new AreaScorer,
        private readonly CounterScorer $counterScorer = new CounterScorer,
        private readonly GlobalScorer $globalScorer = new GlobalScorer,
        private readonly SituationResolver $situationResolver = new SituationResolver,
    ) {}

    public function score(ScoringInput $input, RuleSet $ruleSet): ScoringResult
    {
        $findings = [];
        $chronologicalAgeDays = null;
        $correctedAgeDays = null;

        if ($input->ageGroupCode !== null) {
            $appliedGroupCode = $input->ageGroupCode;
            $substituted = false;

            if (! array_key_exists($appliedGroupCode, $ruleSet->officialGroups())) {
                throw new InvalidScoringInputException(
                    "Unknown age_group_code '{$appliedGroupCode}' -- it must be an official group of the RuleSet."
                );
            }
        } else {
            $ages = $this->ageResolver->resolveAges(
                $input->birthDate,
                $input->testDate,
                $ruleSet->ageCalculationMode,
                $input->gestationalAgeWeeks,
            );
            $chronologicalAgeDays = $ages['chronological_age_days'];
            $correctedAgeDays = $ages['corrected_age_days'];

            $groupResolution = $this->ageGroupResolver->resolveGroup($ruleSet, $chronologicalAgeDays, $correctedAgeDays);
            $appliedGroupCode = $groupResolution['applied_group_code'];
            $substituted = $groupResolution['substituted'];
            $findings = [...$findings, ...$groupResolution['findings']];
        }

        $this->validateAnswers($input, $ruleSet, $appliedGroupCode);

        $derivation = $this->autoAnswerDeriver->derive($input, $ruleSet, $appliedGroupCode, $chronologicalAgeDays);
        $findings = [...$findings, ...$derivation['findings']];

        $answers = $this->mergeAnswers($input->answers, $derivation['head_circumference_answer']);

        $areaResults = [];
        foreach ($ruleSet->semaphoreAreaCodesInGroup($appliedGroupCode) as $areaCode) {
            [$areaResult, $areaFindings] = $this->areaScorer->score($appliedGroupCode, $areaCode, $ruleSet, $answers);
            $areaResults[] = $areaResult;
            $findings = [...$findings, ...$areaFindings];
        }

        $alertSigns = $this->counterScorer->alertSigns($appliedGroupCode, $ruleSet, $answers);
        $riskFactorCount = $this->counterScorer->riskFactors($derivation['risk_factor_answers']);

        $global = $this->globalScorer->score($appliedGroupCode, $ruleSet, $areaResults, $alertSigns, $riskFactorCount);

        $situation = $this->situationResolver->resolveSituation($input->previousResultColorKey, $global['color_key']);

        return new ScoringResult(
            appliedAgeGroupCode: $appliedGroupCode,
            ageGroupSubstituted: $substituted,
            chronologicalAgeDays: $chronologicalAgeDays,
            correctedAgeDays: $correctedAgeDays,
            areaResults: $areaResults,
            alertSignCount: $alertSigns,
            riskFactorCount: $riskFactorCount,
            derivedAnswers: $derivation['derived_answers'],
            globalColorKey: $global['color_key'],
            matchedGlobalRuleSequence: $global['sequence'],
            messageKey: $global['message_key'],
            situation: $situation,
            findings: $findings,
        );
    }

    /**
     * @param  array<int, ItemAnswer>  $answers
     * @return array<int, ItemAnswer>
     */
    private function mergeAnswers(array $answers, ?ItemAnswer $headCircumferenceOverride): array
    {
        if ($headCircumferenceOverride === null) {
            return $answers;
        }

        $filtered = array_values(array_filter(
            $answers,
            fn (ItemAnswer $a) => ! ($a->ageGroupCode === $headCircumferenceOverride->ageGroupCode
                && $a->itemCode === $headCircumferenceOverride->itemCode)
        ));

        $filtered[] = $headCircumferenceOverride;

        return $filtered;
    }

    private function validateAnswers(ScoringInput $input, RuleSet $ruleSet, string $appliedGroupCode): void
    {
        $group = $ruleSet->group($appliedGroupCode);
        $allowedGroupCodes = [$appliedGroupCode];
        if ($group->alternateGroupCode !== null) {
            $allowedGroupCodes[] = $group->alternateGroupCode;
        }

        foreach ($input->answers as $answer) {
            if (! in_array($answer->ageGroupCode, $allowedGroupCodes, true)) {
                throw new InvalidScoringInputException(
                    "Answer references group '{$answer->ageGroupCode}', which is neither the applied group ".
                    "'{$appliedGroupCode}' nor its alternate."
                );
            }

            if (! $ruleSet->hasItem($answer->ageGroupCode, $answer->itemCode)) {
                throw new InvalidScoringInputException(
                    "Answer references unknown item '{$answer->ageGroupCode}/{$answer->itemCode}'."
                );
            }

            $item = $ruleSet->item($answer->ageGroupCode, $answer->itemCode);
            if (! array_key_exists($answer->selectedLabel, $item->optionColorByLabel)) {
                throw new InvalidScoringInputException(
                    "Answer references unknown label '{$answer->selectedLabel}' for item ".
                    "'{$answer->ageGroupCode}/{$answer->itemCode}'."
                );
            }
        }

        foreach (array_keys($input->riskFactorAnswers) as $code) {
            if (! array_key_exists($code, $ruleSet->riskFactors)) {
                throw new InvalidScoringInputException("Unknown risk_factor code '{$code}'.");
            }
        }
    }
}
