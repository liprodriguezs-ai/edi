<?php

namespace Modules\Screening\Services\Scoring\Values;

/**
 * `ScoringEngine::score()`'s single output (design §3/§9, REQ-SCO-009): the
 * full explainable trace of one screening. Contains no UUIDs, timestamps,
 * or `legacy_id` values -- it is meant to be persisted verbatim (as jsonb)
 * by the next change and to reconstruct the outcome for a clinician without
 * re-running the engine.
 */
final readonly class ScoringResult
{
    /**
     * @param  array<int, AreaResult>  $areaResults
     * @param  array<int, DerivedAnswer>  $derivedAnswers
     * @param  array<int, ScoringFinding>  $findings
     */
    public function __construct(
        public string $appliedAgeGroupCode,
        public bool $ageGroupSubstituted,
        public ?int $chronologicalAgeDays,
        public ?int $correctedAgeDays,
        public array $areaResults,
        public int $alertSignCount,
        public int $riskFactorCount,
        public array $derivedAnswers,
        public string $globalColorKey,
        public int $matchedGlobalRuleSequence,
        public string $messageKey,
        public ?SituationOutcome $situation,
        public array $findings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'applied_age_group_code' => $this->appliedAgeGroupCode,
            'age_group_substituted' => $this->ageGroupSubstituted,
            'chronological_age_days' => $this->chronologicalAgeDays,
            'corrected_age_days' => $this->correctedAgeDays,
            'area_results' => array_map(fn (AreaResult $r) => $r->toArray(), $this->areaResults),
            'alert_sign_count' => $this->alertSignCount,
            'risk_factor_count' => $this->riskFactorCount,
            'derived_answers' => array_map(fn (DerivedAnswer $d) => $d->toArray(), $this->derivedAnswers),
            'global_color_key' => $this->globalColorKey,
            'matched_global_rule_sequence' => $this->matchedGlobalRuleSequence,
            'message_key' => $this->messageKey,
            'situation' => $this->situation?->toArray(),
            'findings' => array_map(fn (ScoringFinding $f) => $f->toArray(), $this->findings),
        ];
    }
}
