<?php

declare(strict_types=1);

namespace Modules\Screening\Services;

use DateTimeImmutable;
use Illuminate\Support\Collection;
use Modules\ChildRecord\Models\BirthRecord;
use Modules\ChildRecord\Models\Visit;
use Modules\Instrument\Models\AgeGroup;
use Modules\Instrument\Models\DevelopmentArea;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Instrument\Models\RiskFactor;
use Modules\Instrument\Models\ScreeningItem;
use Modules\Screening\Exceptions\ScreeningDomainException;
use Modules\Screening\Models\Screening;
use Modules\Screening\Models\ScreeningStatus;
use Modules\Screening\Services\Scoring\AgeGroupResolver;
use Modules\Screening\Services\Scoring\AgeResolver;
use Modules\Screening\Services\Scoring\Exceptions\ScreeningNotApplicableException;
use Modules\Screening\Services\Scoring\Values\RuleSet;
use Modules\Screening\Support\PerinatalPlausibilityFilter;

/**
 * Draft creation and item bank building (design §5 step 0, REQ-SCR-002/007).
 * Resolves everything server-side: child from visit, frozen version, ages
 * and groups via the engine's own resolvers -- no request field can
 * influence the result (invariant 1).
 */
final class ScreeningApplicationService
{
    public function __construct(
        private readonly RuleSetProvider $ruleSetProvider,
        private readonly AgeResolver $ageResolver = new AgeResolver,
        private readonly AgeGroupResolver $ageGroupResolver = new AgeGroupResolver,
    ) {}

    /**
     * @param  array{visit_id: string, age_group_id?: string|null, instrument_version_key?: string, weight_grams?: int|null, height_mm?: int|null, head_circumference_mm?: int|null, notes?: string|null}  $data
     * @return array{screening: Screening, item_bank: array<string, mixed>}
     */
    public function createDraft(array $data): array
    {
        $visit = Visit::with('child.birth_record')->findOrFail($data['visit_id']);
        $child = $visit->child;

        if (Screening::where('visit_id', $visit->id)->exists()) {
            throw ScreeningDomainException::visitAlreadyScreened();
        }

        // Normally the default published version. A caller may pin an explicit
        // version (e.g. the legacy migration scores 2019-2026 screenings under
        // `edi-legacy-2013`, the instrument they were applied with) by passing
        // `instrument_version_key`; the tamizaje flow never sets it.
        $version = InstrumentVersion::query()
            ->when(
                isset($data['instrument_version_key']),
                fn ($q) => $q->where('key', $data['instrument_version_key']),
                fn ($q) => $q->where('is_default', true),
            )
            ->whereNotNull('published_at')
            ->firstOrFail();

        $ruleSet = $this->ruleSetProvider->for($version->key);

        $birthDate = new DateTimeImmutable($child->birth_date->toDateString());
        $testDate = new DateTimeImmutable($visit->visit_date->toDateString());
        $gestationalAgeWeeks = PerinatalPlausibilityFilter::gestationalAgeWeeks(
            $child->birth_record?->gestational_age_weeks
        );

        $ages = $this->ageResolver->resolveAges($birthDate, $testDate, $ruleSet->ageCalculationMode, $gestationalAgeWeeks);
        $chronologicalAgeDays = $ages['chronological_age_days'];
        $correctedAgeDays = $ages['corrected_age_days'];

        $chosenGroup = $this->chosenOfficialGroup($data['age_group_id'] ?? null, $version);

        try {
            // The chronological group is resolved even for a chosen group: it keeps
            // the 1-71 month applicability window and records the age-derived group.
            $chronologicalResolution = $this->ageGroupResolver->resolveGroup($ruleSet, $chronologicalAgeDays, null);
            $ageDerivedGroupCode = $chosenGroup !== null
                ? $this->ageDerivedGroupCodeForChosenMode($ruleSet, $chronologicalAgeDays, $correctedAgeDays)
                : $this->ageGroupResolver->resolveGroup($ruleSet, $chronologicalAgeDays, $correctedAgeDays)['applied_group_code'];
            $appliedGroupCode = $chosenGroup?->code ?? $ageDerivedGroupCode;
        } catch (ScreeningNotApplicableException $e) {
            throw ScreeningDomainException::screeningNotApplicable($e->getMessage());
        }

        $ageDerivedGroupId = $ageDerivedGroupCode === null ? null : AgeGroup::query()
            ->where('instrument_version_id', $version->id)
            ->where('code', $ageDerivedGroupCode)
            ->value('id');

        $chronologicalGroupId = AgeGroup::query()
            ->where('instrument_version_id', $version->id)
            ->where('code', $chronologicalResolution['applied_group_code'])
            ->value('id');

        $appliedGroupId = $chosenGroup?->id ?? AgeGroup::query()
            ->where('instrument_version_id', $version->id)
            ->where('code', $appliedGroupCode)
            ->value('id');

        $screening = Screening::forceCreate([
            'visit_id' => $visit->id,
            'child_id' => $child->id,
            'instrument_version_id' => $version->id,
            'age_group_id' => $chronologicalGroupId,
            'applied_age_group_id' => $appliedGroupId,
            'age_group_selection_mode' => $chosenGroup !== null ? Screening::SELECTION_MODE_CHOSEN : Screening::SELECTION_MODE_AGE,
            'age_derived_age_group_id' => $ageDerivedGroupId,
            'chronological_age_days' => $chronologicalAgeDays,
            'corrected_age_days' => $correctedAgeDays,
            'weight_grams' => $data['weight_grams'] ?? null,
            'height_mm' => $data['height_mm'] ?? null,
            'head_circumference_mm' => $data['head_circumference_mm'] ?? null,
            'screening_status_id' => ScreeningStatus::DRAFT,
            'notes' => $data['notes'] ?? null,
        ]);

        return [
            'screening' => $screening,
            'item_bank' => $this->itemBankFor($screening, $ruleSet, $appliedGroupCode),
        ];
    }

    /**
     * In chosen mode the age-derived group is informational only ("por edad
     * correspondía el Grupo N"), so a corrected age outside the instrument
     * range must not block the screening: it just records no group.
     */
    private function ageDerivedGroupCodeForChosenMode(RuleSet $ruleSet, int $chronologicalAgeDays, ?int $correctedAgeDays): ?string
    {
        try {
            return $this->ageGroupResolver->resolveGroup($ruleSet, $chronologicalAgeDays, $correctedAgeDays)['applied_group_code'];
        } catch (ScreeningNotApplicableException) {
            return null;
        }
    }

    /**
     * "Prueba EDI a elección" (S2). The request already validated the id; this
     * re-checks it against the version actually frozen on the draft, so a caller
     * pinning another version can never mix one version's group into another.
     */
    private function chosenOfficialGroup(?string $ageGroupId, InstrumentVersion $version): ?AgeGroup
    {
        if ($ageGroupId === null) {
            return null;
        }

        $group = AgeGroup::query()
            ->where('id', $ageGroupId)
            ->where('instrument_version_id', $version->id)
            ->where('is_alternate', false)
            ->first();

        if ($group === null) {
            throw ScreeningDomainException::screeningNotApplicable(
                'El grupo elegido no es un grupo oficial de la versión de la Prueba EDI que se aplicará.'
            );
        }

        return $group;
    }

    /**
     * @return array{screening: Screening, item_bank: array<string, mixed>}
     */
    public function itemBank(Screening $screening): array
    {
        $version = $screening->instrument_version;
        $ruleSet = $this->ruleSetProvider->for($version->key);
        $appliedGroupCode = $screening->applied_age_group->code;

        return [
            'screening' => $screening,
            'item_bank' => $this->itemBankFor($screening, $ruleSet, $appliedGroupCode),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemBankFor(Screening $screening, RuleSet $ruleSet, string $appliedGroupCode): array
    {
        $group = $ruleSet->group($appliedGroupCode);
        $version = $screening->instrument_version ?? InstrumentVersion::findOrFail($screening->instrument_version_id);

        $appliedGroupModel = AgeGroup::query()
            ->where('instrument_version_id', $version->id)
            ->where('code', $appliedGroupCode)
            ->firstOrFail();

        $alternateGroupModel = $group->alternateGroupCode !== null
            ? AgeGroup::query()->where('instrument_version_id', $version->id)->where('code', $group->alternateGroupCode)->first()
            : null;

        // A chosen group is scored with an explicit group (no dates), so the engine
        // cannot derive the head-circumference item from the percentile table:
        // the evaluator answers it like any other item.
        $headCircumferenceItemCode = $screening->isAgeGroupChosen()
            ? null
            : $ruleSet->headCircumferenceItemCodeFor($appliedGroupCode);
        $headCircumferenceMm = $screening->head_circumference_mm;

        $developmentAreaByCode = DevelopmentArea::query()->get()->keyBy('code');

        $areas = [];
        foreach ($this->itemsFor($appliedGroupModel->id, false) as $item) {
            $this->appendItemToArea($areas, $item, false, $developmentAreaByCode, $headCircumferenceItemCode, $headCircumferenceMm);
        }
        if ($alternateGroupModel !== null) {
            foreach ($this->itemsFor($alternateGroupModel->id, true) as $item) {
                $this->appendItemToArea($areas, $item, true, $developmentAreaByCode, $headCircumferenceItemCode, $headCircumferenceMm);
            }
        }

        return [
            'applied_age_group_code' => $appliedGroupCode,
            'areas' => array_values($areas),
            'risk_factors' => $this->riskFactorBank($version, $screening->child?->birth_record),
        ];
    }

    /**
     * @return Collection<int, ScreeningItem>
     */
    private function itemsFor(string $ageGroupId, bool $isAlternate)
    {
        return ScreeningItem::query()
            ->with(['options', 'development_area'])
            ->where('age_group_id', $ageGroupId)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @param  array<string, array<string, mixed>>  $areas
     */
    private function appendItemToArea(
        array &$areas,
        ScreeningItem $item,
        bool $isAlternate,
        Collection $developmentAreaByCode,
        ?string $headCircumferenceItemCode,
        ?int $headCircumferenceMm,
    ): void {
        $areaModel = $item->development_area;
        $areaCode = $areaModel?->code;

        $areas[$areaCode] ??= [
            'development_area_id' => $areaModel?->id,
            'code' => $areaCode,
            'name' => $areaModel?->name,
            'items' => [],
        ];

        $locked = ! $isAlternate
            && $headCircumferenceItemCode !== null
            && $item->code === $headCircumferenceItemCode
            && $headCircumferenceMm !== null;

        $areas[$areaCode]['items'][] = [
            'screening_item_id' => $item->id,
            'code' => $item->code,
            'question' => $item->question,
            'is_alternate' => $isAlternate,
            'locked' => $locked,
            'locked_reason' => $locked ? [
                'auto_derivation_key' => 'head_circumference',
                'source_field' => 'head_circumference_mm',
                'value' => $headCircumferenceMm,
            ] : null,
            'options' => $item->options->map(fn ($option) => [
                'screening_item_option_id' => $option->id,
                'label' => $option->label,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function riskFactorBank(InstrumentVersion $version, ?BirthRecord $birthRecord): array
    {
        $factors = RiskFactor::query()
            ->where('instrument_version_id', $version->id)
            ->orderBy('sort_order')
            ->get();

        return $factors->map(function (RiskFactor $factor) use ($birthRecord) {
            [$locked, $reason] = $this->lockedRiskFactor($factor, $birthRecord);

            return [
                'risk_factor_id' => $factor->id,
                'code' => $factor->code,
                'question' => $factor->question,
                'locked' => $locked,
                'locked_reason' => $reason,
            ];
        })->values()->all();
    }

    /**
     * @return array{0: bool, 1: array<string, mixed>|null}
     */
    private function lockedRiskFactor(RiskFactor $factor, ?BirthRecord $birthRecord): array
    {
        if ($birthRecord === null || $factor->auto_derivation_key === null) {
            return [false, null];
        }

        [$sourceField, $plausibleValue] = match ($factor->auto_derivation_key) {
            RiskFactor::AUTO_DERIVATION_GESTATIONAL_AGE => [
                'gestational_age_weeks',
                PerinatalPlausibilityFilter::gestationalAgeWeeks($birthRecord->gestational_age_weeks),
            ],
            RiskFactor::AUTO_DERIVATION_BIRTH_WEIGHT => [
                'birth_weight_grams',
                PerinatalPlausibilityFilter::birthWeightGrams($birthRecord->birth_weight_grams),
            ],
            RiskFactor::AUTO_DERIVATION_MOTHER_AGE => [
                'mother_age_at_birth_years',
                PerinatalPlausibilityFilter::motherAgeAtBirthYears($birthRecord->mother_age_at_birth_years),
            ],
            default => [null, null],
        };

        if ($sourceField === null || $plausibleValue === null) {
            return [false, null];
        }

        return [true, [
            'auto_derivation_key' => $factor->auto_derivation_key,
            'source_field' => $sourceField,
            'value' => $plausibleValue,
        ]];
    }
}
