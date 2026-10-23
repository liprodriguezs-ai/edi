<?php

declare(strict_types=1);

namespace Modules\Screening\Services;

use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Instrument\Models\AgeGroup;
use Modules\Instrument\Models\DevelopmentArea;
use Modules\Instrument\Models\GlobalResultMessage;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Instrument\Models\ResultColor;
use Modules\Instrument\Models\RiskFactor;
use Modules\Instrument\Models\ScreeningItem;
use Modules\Instrument\Models\ScreeningItemOption;
use Modules\Screening\Exceptions\ScreeningDomainException;
use Modules\Screening\Models\FindingType;
use Modules\Screening\Models\Screening;
use Modules\Screening\Models\ScreeningAnswer;
use Modules\Screening\Models\ScreeningAreaResult;
use Modules\Screening\Models\ScreeningFinding as ScreeningFindingModel;
use Modules\Screening\Models\ScreeningRiskFactorAnswer;
use Modules\Screening\Models\ScreeningScoreRun;
use Modules\Screening\Models\ScreeningSituation;
use Modules\Screening\Models\ScreeningStatus;
use Modules\Screening\Services\Scoring\AgeGroupResolver;
use Modules\Screening\Services\Scoring\AgeResolver;
use Modules\Screening\Services\Scoring\Exceptions\InvalidScoringInputException;
use Modules\Screening\Services\Scoring\ScoringEngine;
use Modules\Screening\Services\Scoring\Values\DerivedAnswer;
use Modules\Screening\Services\Scoring\Values\ItemAnswer;
use Modules\Screening\Services\Scoring\Values\RuleSet;
use Modules\Screening\Services\Scoring\Values\ScoringInput;
use Modules\Screening\Services\Scoring\Values\ScoringResult;
use Modules\Screening\Support\PerinatalPlausibilityFilter;

/**
 * The score pipeline (design §5, REQ-SCR-005/006/007/008/012): the
 * invariant core. Gates run BEFORE any write (D11) so "nothing persisted on
 * a 422" is true by construction -- everything, gates included, runs inside
 * ONE lockForUpdate transaction (D19), and a thrown exception rolls the
 * whole thing back.
 */
final class ScreeningScoreService
{
    /**
     * Consumer version tag recorded on the header and every run (D10). The
     * ENGINE itself is frozen; this constant is owned by the consumer and
     * bumped only on a release that changes THIS service's behavior.
     */
    public const ENGINE_VERSION = '1.0.0';

    public function __construct(
        private readonly RuleSetProvider $ruleSetProvider,
        private readonly AlternateRequirementService $alternateRequirements,
        private readonly ScoringEngine $engine = new ScoringEngine,
        private readonly AgeResolver $ageResolver = new AgeResolver,
        private readonly AgeGroupResolver $ageGroupResolver = new AgeGroupResolver,
    ) {}

    public function score(Screening $screening): Screening
    {
        return DB::transaction(function () use ($screening): Screening {
            /** @var Screening $locked */
            $locked = Screening::query()->whereKey($screening->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->screening_status_id, [ScreeningStatus::COMPLETED, ScreeningStatus::INVALIDATED], true)) {
                throw ScreeningDomainException::screeningFrozen();
            }

            $version = InstrumentVersion::findOrFail($locked->instrument_version_id);
            $ruleSet = $this->ruleSetProvider->for($version->key);
            $appliedGroupCode = $ruleSet->group($this->groupCode($locked->applied_age_group_id, $version->id))->code;

            $appliedGroupModel = AgeGroup::findOrFail($locked->applied_age_group_id);
            $group = $ruleSet->group($appliedGroupCode);
            $alternateGroupModel = $group->alternateGroupCode !== null
                ? AgeGroup::where('instrument_version_id', $version->id)->where('code', $group->alternateGroupCode)->first()
                : null;

            $answers = ScreeningAnswer::with(['screening_item', 'screening_item_option'])
                ->where('screening_id', $locked->id)
                ->get();

            $groupCodeById = [$appliedGroupModel->id => $appliedGroupCode];
            if ($alternateGroupModel !== null) {
                $groupCodeById[$alternateGroupModel->id] = $group->alternateGroupCode;
            }

            // With a head circumference recorded, the UI locks that item (no radio
            // to answer) because the engine derives it from the measurement against
            // the percentile table. Demanding a manual answer for it anyway made the
            // screening impossible to score: every section read complete, yet scoring
            // failed as incomplete pointing at an item the form does not let anyone
            // answer. The only escape was deleting the measurement.
            // A chosen group scores without dates (forGroup), so nothing is derived.
            $engineDerivedItemCode = $locked->head_circumference_mm !== null && ! $locked->isAgeGroupChosen()
                ? $ruleSet->headCircumferenceItemCodeFor($appliedGroupCode)
                : null;

            $this->assertCompleteness($ruleSet, $appliedGroupCode, $appliedGroupModel, $answers, $groupCodeById, $engineDerivedItemCode);
            $this->assertAlternateAnswered($ruleSet, $appliedGroupCode, $version, $answers, $groupCodeById);

            $isInitial = ScreeningScoreRun::where('screening_id', $locked->id)->doesntExist();

            $input = match (true) {
                ! $isInitial => $this->buildRescoreInput($locked, $ruleSet, $appliedGroupCode, $answers, $groupCodeById, $version),
                $locked->isAgeGroupChosen() => $this->buildRescoreInput(
                    $locked,
                    $ruleSet,
                    $appliedGroupCode,
                    $answers,
                    $groupCodeById,
                    $version,
                    $this->resolveAndFreezePreviousColor($locked),
                ),
                default => $this->buildInitialInput($locked, $ruleSet, $appliedGroupCode, $answers, $groupCodeById, $version),
            };

            try {
                $result = $this->engine->score($input, $ruleSet);
            } catch (InvalidScoringInputException $e) {
                throw ScreeningDomainException::screeningNotApplicable($e->getMessage());
            }

            $this->persistAuthoritativeResult(
                $locked,
                $version,
                $appliedGroupModel,
                $result,
                $isInitial ? ScreeningScoreRun::TRIGGER_INITIAL : ScreeningScoreRun::TRIGGER_RESCORE,
                null,
                $input->previousResultColorKey,
                true,
            );

            return $locked->fresh();
        });
    }

    /**
     * Builds a ScoringInput for an ALREADY-scored screening against a given
     * target RuleSet (design D6 forGroup path), reusable by
     * ScreeningLifecycleService::rescore for both the authoritative
     * (own-version) and non-authoritative (any published version, D8)
     * branches. Answers/risk-factor answers are always decoded against the
     * screening's OWN version (their real FK target); only the RuleSet used
     * to INTERPRET those natural keys varies.
     */
    public function buildInputForRescore(Screening $screening, InstrumentVersion $ownVersion, RuleSet $targetRuleSet): ScoringInput
    {
        $appliedGroupModel = AgeGroup::findOrFail($screening->applied_age_group_id);
        $appliedGroupCode = $appliedGroupModel->code;
        $group = $targetRuleSet->group($appliedGroupCode);

        $alternateGroupModel = $group->alternateGroupCode !== null
            ? AgeGroup::where('instrument_version_id', $ownVersion->id)->where('code', $group->alternateGroupCode)->first()
            : null;

        $groupCodeById = [$appliedGroupModel->id => $appliedGroupCode];
        if ($alternateGroupModel !== null) {
            $groupCodeById[$alternateGroupModel->id] = $group->alternateGroupCode;
        }

        $answers = ScreeningAnswer::with(['screening_item', 'screening_item_option'])
            ->where('screening_id', $screening->id)
            ->get();

        return $this->buildRescoreInput($screening, $targetRuleSet, $appliedGroupCode, $answers, $groupCodeById, $ownVersion);
    }

    /**
     * Runs the frozen engine against a pre-built input/RuleSet pair,
     * translating its checked exceptions into the typed domain envelope
     * (design §4). Reusable by ScreeningLifecycleService::rescore.
     */
    public function runEngine(ScoringInput $input, RuleSet $ruleSet): ScoringResult
    {
        try {
            return $this->engine->score($input, $ruleSet);
        } catch (InvalidScoringInputException $e) {
            throw ScreeningDomainException::screeningNotApplicable($e->getMessage());
        }
    }

    private function groupCode(string $ageGroupId, string $versionId): string
    {
        return AgeGroup::where('id', $ageGroupId)->value('code')
            ?? throw new \RuntimeException("age_group '{$ageGroupId}' not found for instrument_version '{$versionId}'.");
    }

    /**
     * @param  Collection<int, ScreeningAnswer>  $answers
     * @param  array<string, string>  $groupCodeById
     */
    private function assertCompleteness(RuleSet $ruleSet, string $appliedGroupCode, AgeGroup $appliedGroupModel, Collection $answers, array $groupCodeById, ?string $engineDerivedItemCode = null): void
    {
        $ownItems = ScreeningItem::where('age_group_id', $appliedGroupModel->id)->get();

        $answeredItemIds = $answers->pluck('screening_item_id')->all();

        $missing = $ownItems->filter(
            fn (ScreeningItem $item) => ! in_array($item->id, $answeredItemIds, true)
                && $item->code !== $engineDerivedItemCode
        );

        if ($missing->isNotEmpty()) {
            throw ScreeningDomainException::incompleteAnswers($missing->pluck('id')->values()->all());
        }
    }

    /**
     * 422 ALTERNATE_ITEMS_REQUIRED when an axis needs the alternate group and
     * any of its alternate items is still unanswered. The rule itself lives in
     * AlternateRequirementService, shared with the answer save and item bank.
     *
     * @param  Collection<int, ScreeningAnswer>  $answers
     * @param  array<string, string>  $groupCodeById
     */
    private function assertAlternateAnswered(
        RuleSet $ruleSet,
        string $appliedGroupCode,
        InstrumentVersion $version,
        Collection $answers,
        array $groupCodeById,
    ): void {
        $areasNeedingAlternate = [];
        foreach ($this->alternateRequirements->evaluate($ruleSet, $appliedGroupCode, $version, $answers, $groupCodeById) as $requirement) {
            if ($requirement['missing_items'] === []) {
                continue;
            }

            $areasNeedingAlternate[] = [
                'development_area_id' => $requirement['development_area_id'],
                'code' => $requirement['code'],
                'missing_items' => array_map(fn (array $item) => [
                    'screening_item_id' => $item['screening_item_id'],
                    'code' => $item['code'],
                    'question' => $item['question'],
                    'options' => $item['options'],
                ], $requirement['missing_items']),
            ];
        }

        if ($areasNeedingAlternate !== []) {
            throw ScreeningDomainException::alternateItemsRequired($areasNeedingAlternate);
        }
    }

    /**
     * @param  Collection<int, ScreeningAnswer>  $answers
     * @param  array<string, string>  $groupCodeById
     */
    private function buildInitialInput(
        Screening $screening,
        RuleSet $ruleSet,
        string $appliedGroupCode,
        Collection $answers,
        array $groupCodeById,
        InstrumentVersion $version,
    ): ScoringInput {
        $child = $screening->child;
        $visit = $screening->visit;
        $birthRecord = $child->birth_record;

        $birthDate = new DateTimeImmutable($child->birth_date->toDateString());
        $testDate = new DateTimeImmutable($visit->visit_date->toDateString());

        $gestationalAgeWeeks = PerinatalPlausibilityFilter::gestationalAgeWeeks($birthRecord?->gestational_age_weeks);
        $ages = $this->ageResolver->resolveAges($birthDate, $testDate, $ruleSet->ageCalculationMode, $gestationalAgeWeeks);

        $groupResolution = $this->ageGroupResolver->resolveGroup($ruleSet, $ages['chronological_age_days'], $ages['corrected_age_days']);

        if ($groupResolution['applied_group_code'] !== $appliedGroupCode) {
            throw new \RuntimeException(
                "Tripwire failed: date-resolved applied group '{$groupResolution['applied_group_code']}' does not match ".
                "the stored applied_age_group_id group '{$appliedGroupCode}' for screening '{$screening->id}'."
            );
        }

        $sexKey = $child->sex?->key;

        return ScoringInput::forDates(
            birthDate: $birthDate,
            testDate: $testDate,
            answers: $this->itemAnswers($answers, $groupCodeById),
            riskFactorAnswers: $this->riskFactorAnswers($screening, $version),
            sexKey: $sexKey,
            gestationalAgeWeeks: $gestationalAgeWeeks,
            birthWeightGrams: PerinatalPlausibilityFilter::birthWeightGrams($birthRecord?->birth_weight_grams),
            motherAgeAtBirthYears: PerinatalPlausibilityFilter::motherAgeAtBirthYears($birthRecord?->mother_age_at_birth_years),
            headCircumferenceMm: $screening->head_circumference_mm,
            previousResultColorKey: $this->resolveAndFreezePreviousColor($screening),
        );
    }

    /**
     * The forGroup path: the stored applied group is given explicitly instead of
     * being re-resolved from dates. Used by every rescore and by the FIRST score
     * of a "Prueba EDI a elección" screening, whose group was picked by the
     * evaluator and therefore never matches the date-resolved one (the
     * buildInitialInput tripwire would reject it). That first score passes the
     * previous color it just froze; rescores reuse the frozen column.
     *
     * @param  Collection<int, ScreeningAnswer>  $answers
     * @param  array<string, string>  $groupCodeById
     */
    private function buildRescoreInput(
        Screening $screening,
        RuleSet $ruleSet,
        string $appliedGroupCode,
        Collection $answers,
        array $groupCodeById,
        InstrumentVersion $version,
        ?string $frozenPreviousResultColorKey = null,
    ): ScoringInput {
        $child = $screening->child;
        $previousResultColorKey = $frozenPreviousResultColorKey ?? ($screening->previous_result_color_id !== null
            ? ResultColor::find($screening->previous_result_color_id)?->key
            : null);

        return ScoringInput::forGroup(
            ageGroupCode: $appliedGroupCode,
            answers: $this->itemAnswers($answers, $groupCodeById),
            riskFactorAnswers: $this->riskFactorAnswers($screening, $version),
            sexKey: $child->sex?->key,
            gestationalAgeWeeks: PerinatalPlausibilityFilter::gestationalAgeWeeks($child->birth_record?->gestational_age_weeks),
            birthWeightGrams: PerinatalPlausibilityFilter::birthWeightGrams($child->birth_record?->birth_weight_grams),
            motherAgeAtBirthYears: PerinatalPlausibilityFilter::motherAgeAtBirthYears($child->birth_record?->mother_age_at_birth_years),
            headCircumferenceMm: $screening->head_circumference_mm,
            previousResultColorKey: $previousResultColorKey,
        );
    }

    /**
     * @param  Collection<int, ScreeningAnswer>  $answers
     * @param  array<string, string>  $groupCodeById
     * @return array<int, ItemAnswer>
     */
    private function itemAnswers(Collection $answers, array $groupCodeById): array
    {
        $result = [];
        foreach ($answers as $answer) {
            $groupCode = $groupCodeById[$answer->screening_item->age_group_id] ?? null;
            if ($groupCode === null) {
                continue;
            }
            $result[] = new ItemAnswer($groupCode, $answer->screening_item->code, $answer->screening_item_option->label);
        }

        return $result;
    }

    /**
     * @return array<string, bool|null>
     */
    private function riskFactorAnswers(Screening $screening, InstrumentVersion $version): array
    {
        $codeById = RiskFactor::where('instrument_version_id', $version->id)->pluck('code', 'id');

        $result = [];
        foreach (ScreeningRiskFactorAnswer::where('screening_id', $screening->id)->get() as $answer) {
            $code = $codeById[$answer->risk_factor_id] ?? null;
            if ($code !== null) {
                $result[$code] = (bool) $answer->is_present;
            }
        }

        return $result;
    }

    /**
     * D16 — resolved and frozen ONLY at the very first score. Later calls
     * must never reach this method (buildRescoreInput reuses the frozen
     * column directly).
     */
    private function resolveAndFreezePreviousColor(Screening $screening): ?string
    {
        if ($screening->previous_screening_id !== null || $screening->previous_result_color_id !== null) {
            return $screening->previous_result_color_id !== null
                ? ResultColor::find($screening->previous_result_color_id)?->key
                : null;
        }

        $thisVisitDate = $screening->visit->visit_date;

        $previous = Screening::with('visit')
            ->where('child_id', $screening->child_id)
            ->where('id', '!=', $screening->id)
            ->where('screening_status_id', ScreeningStatus::COMPLETED)
            ->get()
            ->filter(fn (Screening $s) => $s->visit !== null && $s->visit->visit_date->lt($thisVisitDate))
            ->sort(function (Screening $a, Screening $b) {
                $cmp = $b->visit->visit_date <=> $a->visit->visit_date;

                return $cmp !== 0 ? $cmp : ($b->scored_at <=> $a->scored_at);
            })
            ->first();

        $screening->previous_screening_id = $previous?->id;
        $screening->previous_result_color_id = $previous?->result_color_id;
        $screening->save();

        return $previous?->result_color_id !== null ? ResultColor::find($previous->result_color_id)?->key : null;
    }

    /**
     * ONE-transaction persist of an authoritative engine result (design D7):
     * header + area results + effective (incl. auto-derived) answers + FRB
     * answers + findings + the authoritative screening_score_run. Reusable
     * by both the initial/repeat POST /score path (updateStatus = true,
     * flips to `scored`) and an authoritative POST /rescore (updateStatus =
     * false, D8/D10 — status is NEVER changed by a rescore).
     */
    public function persistAuthoritativeResult(
        Screening $screening,
        InstrumentVersion $version,
        AgeGroup $appliedGroupModel,
        ScoringResult $result,
        string $trigger,
        ?string $reason,
        ?string $previousResultColorKeyUsed,
        bool $updateStatus,
    ): void {
        $resultColorId = ResultColor::where('key', $result->globalColorKey)->value('id');

        $globalResultMessageId = GlobalResultMessage::where('instrument_version_id', $version->id)
            ->where('key', $result->messageKey)
            ->value('id');

        $situationId = $result->situation !== null
            ? ScreeningSituation::where('key', $result->situation->situationKey)->value('id')
            : null;

        $developmentAreaIdByCode = DevelopmentArea::pluck('id', 'code');

        foreach ($result->areaResults as $areaResult) {
            ScreeningAreaResult::updateOrCreate(
                ['screening_id' => $screening->id, 'development_area_id' => $developmentAreaIdByCode[$areaResult->areaCode] ?? null],
                [
                    'result_color_id' => ResultColor::where('key', $areaResult->colorKey)->value('id'),
                    'red_count' => $areaResult->reds,
                    'yellow_count' => $areaResult->yellows,
                    'green_count' => $areaResult->greens,
                    'answered_own_count' => $areaResult->answeredOwn,
                    'answered_alternate_count' => $areaResult->answeredAlternate,
                    'red_threshold' => $areaResult->redThreshold,
                    'green_threshold' => $areaResult->greenThreshold,
                    'allows_yellow' => $areaResult->allowsYellow,
                ],
            );
        }

        $this->persistDerivedAnswers($screening, $version, $appliedGroupModel, $result->derivedAnswers);

        ScreeningFindingModel::where('screening_id', $screening->id)->delete();
        $findingTypeIdByKey = FindingType::pluck('id', 'key');
        foreach ($result->findings as $finding) {
            ScreeningFindingModel::create([
                'screening_id' => $screening->id,
                'finding_type_id' => $findingTypeIdByKey[$finding->key] ?? null,
                'context' => $finding->context,
            ]);
        }

        ScreeningScoreRun::create([
            'screening_id' => $screening->id,
            'instrument_version_id' => $version->id,
            'is_authoritative' => true,
            'trigger' => $trigger,
            'reason' => $reason,
            'engine_version' => self::ENGINE_VERSION,
            'rules_fingerprint' => $version->content_hash,
            'input_payload' => [
                'applied_age_group_code' => $result->appliedAgeGroupCode,
                'previous_result_color_key' => $previousResultColorKeyUsed,
            ],
            'result_payload' => $result->toArray(),
            'result_color_id' => $resultColorId,
        ]);

        $headerAttributes = [
            'result_color_id' => $resultColorId,
            'global_result_message_id' => $globalResultMessageId,
            'situation_id' => $situationId,
            'alert_sign_count' => $result->alertSignCount,
            'risk_factor_count' => $result->riskFactorCount,
            'engine_version' => self::ENGINE_VERSION,
            'rules_fingerprint' => $version->content_hash,
            'scored_at' => $screening->scored_at ?? now(),
        ];

        if ($updateStatus) {
            $headerAttributes['screening_status_id'] = ScreeningStatus::SCORED;
        }

        $screening->forceFill($headerAttributes)->save();
    }

    /**
     * @param  array<int, DerivedAnswer>  $derivedAnswers
     */
    private function persistDerivedAnswers(Screening $screening, InstrumentVersion $version, AgeGroup $appliedGroupModel, array $derivedAnswers): void
    {
        foreach ($derivedAnswers as $derived) {
            if ($derived->kind === DerivedAnswer::KIND_HEAD_CIRCUMFERENCE_ITEM) {
                $itemModel = ScreeningItem::where('age_group_id', $appliedGroupModel->id)->where('code', $derived->code)->first();
                if ($itemModel === null) {
                    continue;
                }

                $optionModel = ScreeningItemOption::where('screening_item_id', $itemModel->id)
                    ->where('label', $derived->value)
                    ->first();

                if ($optionModel === null) {
                    continue;
                }

                ScreeningAnswer::updateOrCreate(
                    ['screening_id' => $screening->id, 'screening_item_id' => $itemModel->id],
                    [
                        'screening_item_option_id' => $optionModel->id,
                        'result_color_id' => $optionModel->result_color_id,
                        'is_auto_derived' => true,
                    ],
                );

                continue;
            }

            if ($derived->kind === DerivedAnswer::KIND_RISK_FACTOR) {
                $riskFactorId = RiskFactor::where('instrument_version_id', $version->id)
                    ->where('code', $derived->code)
                    ->value('id');

                if ($riskFactorId === null) {
                    continue;
                }

                ScreeningRiskFactorAnswer::updateOrCreate(
                    ['screening_id' => $screening->id, 'risk_factor_id' => $riskFactorId],
                    ['is_present' => (bool) $derived->value, 'is_auto_derived' => true],
                );
            }
        }
    }
}
