<?php

namespace Modules\Screening\Services\RuleSetLoading;

use Modules\ChildRecord\Models\Sex;
use Modules\Instrument\Models\AgeGroup;
use Modules\Instrument\Models\AreaScoringRule;
use Modules\Instrument\Models\GlobalScoringRule;
use Modules\Instrument\Models\HeadCircumferenceReference;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Instrument\Models\ResultColor;
use Modules\Instrument\Models\RiskFactor;
use Modules\Instrument\Models\ScreeningItem;
use Modules\Screening\Services\Scoring\Values\AgeGroupDefinition;
use Modules\Screening\Services\Scoring\Values\AreaScoringRuleDefinition;
use Modules\Screening\Services\Scoring\Values\GlobalScoringRuleDefinition;
use Modules\Screening\Services\Scoring\Values\HeadCircumferenceBand;
use Modules\Screening\Services\Scoring\Values\RiskFactorDefinition;
use Modules\Screening\Services\Scoring\Values\RuleSet;
use Modules\Screening\Services\Scoring\Values\ScreeningItemDefinition;
use RuntimeException;

/**
 * Builds a {@see RuleSet} from the seeded `Instrument` Eloquent models,
 * resolving every UUID to a natural key exactly like
 * `Modules\Instrument\Services\InstrumentContentHasher` does (design D3).
 * This is the PRODUCTION loader -- the next change memoizes it per version
 * key. `tests/Feature/RuleSetParityTest.php` asserts it produces the exact
 * same RuleSet as {@see CsvRuleSetLoader} for both shipped versions
 * (REQ-SCO-002).
 */
final class DatabaseRuleSetLoader
{
    private const AREA_EN = 'EN';

    private const HEAD_CIRCUMFERENCE_SORT_ORDER = 3;

    public function build(string $versionKey): RuleSet
    {
        $version = InstrumentVersion::query()->where('key', $versionKey)->first();

        if ($version === null) {
            throw new RuntimeException("Unknown instrument_version key '{$versionKey}'.");
        }

        $ageGroupModels = AgeGroup::query()->where('instrument_version_id', $version->id)->get();
        $codeById = $ageGroupModels->pluck('code', 'id');

        $ageGroups = [];
        foreach ($ageGroupModels as $model) {
            $ageGroups[$model->code] = new AgeGroupDefinition(
                code: $model->code,
                isAlternate: (bool) $model->is_alternate,
                minAgeDays: $model->min_age_days,
                maxAgeDays: $model->max_age_days,
                alternateGroupCode: $model->alternate_age_group_id !== null
                    ? ($codeById[$model->alternate_age_group_id] ?? null)
                    : null,
                legacyId: $model->legacy_id,
            );
        }

        $groupCodeByLegacyId = [];
        foreach ($ageGroups as $group) {
            if ($group->legacyId !== null) {
                $groupCodeByLegacyId[$group->legacyId] = $group->code;
            }
        }

        $itemModels = ScreeningItem::query()
            ->with(['options.result_color:id,key', 'development_area:id,code'])
            ->where('instrument_version_id', $version->id)
            ->get();

        $items = [];
        $itemByLegacyId = [];
        foreach ($itemModels as $itemModel) {
            $groupCode = $codeById[$itemModel->age_group_id] ?? null;
            if ($groupCode === null) {
                throw new RuntimeException("screening_item '{$itemModel->id}' references an unknown age_group.");
            }

            $optionColorByLabel = [];
            foreach ($itemModel->options as $option) {
                $optionColorByLabel[$option->label] = $option->result_color?->key
                    ?? throw new RuntimeException("screening_item_option '{$option->id}' has no result_color.");
            }

            $item = new ScreeningItemDefinition(
                groupCode: $groupCode,
                itemCode: $itemModel->code,
                areaCode: $itemModel->development_area?->code
                    ?? throw new RuntimeException("screening_item '{$itemModel->id}' has no development_area."),
                optionColorByLabel: $optionColorByLabel,
                sortOrder: $itemModel->sort_order,
                legacyId: $itemModel->legacy_id,
            );

            $items["{$item->groupCode}/{$item->itemCode}"] = $item;

            if ($item->legacyId !== null) {
                $itemByLegacyId[$item->legacyId] = ['group_code' => $item->groupCode, 'item_code' => $item->itemCode];
            }
        }

        $areaRuleModels = AreaScoringRule::query()
            ->with('development_area:id,code')
            ->where('instrument_version_id', $version->id)
            ->get();

        $areaRules = [];
        $alternateAreaRules = [];
        foreach ($areaRuleModels as $ruleModel) {
            $groupCode = $codeById[$ruleModel->age_group_id] ?? null;
            $group = $groupCode !== null ? ($ageGroups[$groupCode] ?? null) : null;
            if ($group === null) {
                throw new RuntimeException("area_scoring_rule '{$ruleModel->id}' references an unknown age_group.");
            }

            $rule = new AreaScoringRuleDefinition(
                groupCode: $groupCode,
                areaCode: $ruleModel->development_area?->code
                    ?? throw new RuntimeException("area_scoring_rule '{$ruleModel->id}' has no development_area."),
                itemCount: $ruleModel->item_count,
                redThreshold: $ruleModel->red_threshold,
                greenThreshold: $ruleModel->green_threshold,
                allowsYellow: (bool) $ruleModel->allows_yellow,
            );

            $key = "{$rule->groupCode}/{$rule->areaCode}";

            if ($group->isAlternate) {
                $alternateAreaRules[$key] = $rule;
            } else {
                $areaRules[$key] = $rule;
            }
        }

        $colorKeyById = ResultColor::query()->pluck('key', 'id');

        $globalRuleModels = GlobalScoringRule::query()
            ->with('message:id,key')
            ->where('instrument_version_id', $version->id)
            ->get();

        $globalRules = [];
        foreach ($globalRuleModels as $ruleModel) {
            $groupCode = $codeById[$ruleModel->age_group_id] ?? null;
            if ($groupCode === null) {
                throw new RuntimeException("global_scoring_rule '{$ruleModel->id}' references an unknown age_group.");
            }

            $globalRules[$groupCode][] = new GlobalScoringRuleDefinition(
                groupCode: $groupCode,
                sequence: $ruleModel->sequence,
                condition: $ruleModel->condition,
                colorKey: $colorKeyById[$ruleModel->result_color_id] ?? throw new RuntimeException(
                    "global_scoring_rule '{$ruleModel->id}' has no result_color."
                ),
                messageKey: $ruleModel->message?->key
                    ?? throw new RuntimeException("global_scoring_rule '{$ruleModel->id}' has no message."),
            );
        }

        foreach ($globalRules as &$rules) {
            usort($rules, fn (GlobalScoringRuleDefinition $a, GlobalScoringRuleDefinition $b) => $a->sequence <=> $b->sequence);
        }
        unset($rules);

        $riskFactorModels = RiskFactor::query()->where('instrument_version_id', $version->id)->get();

        $riskFactors = [];
        $riskFactorCodeByLegacyId = [];
        foreach ($riskFactorModels as $factorModel) {
            $factor = new RiskFactorDefinition(
                code: $factorModel->code,
                autoDerivationKey: $factorModel->auto_derivation_key,
                legacyId: $factorModel->legacy_id,
            );

            $riskFactors[$factor->code] = $factor;

            if ($factor->legacyId !== null) {
                $riskFactorCodeByLegacyId[$factor->legacyId] = $factor->code;
            }
        }

        $sexKeyById = Sex::query()->pluck('key', 'id');

        $hcModels = HeadCircumferenceReference::query()->where('instrument_version_id', $version->id)->get();

        $headCircumferenceBands = [];
        foreach ($hcModels as $hcModel) {
            $sexKey = $sexKeyById[$hcModel->sex_id] ?? throw new RuntimeException(
                "head_circumference_reference '{$hcModel->id}' has no sex."
            );

            $band = new HeadCircumferenceBand(
                sexKey: $sexKey,
                ageMonths: $hcModel->age_months,
                minMm: $hcModel->min_circumference_mm,
                maxMm: $hcModel->max_circumference_mm,
            );

            $headCircumferenceBands["{$band->sexKey}/{$band->ageMonths}"] = $band;
        }

        $headCircumferenceItemByGroup = [];
        foreach ($items as $item) {
            if ($item->areaCode === self::AREA_EN && $item->sortOrder === self::HEAD_CIRCUMFERENCE_SORT_ORDER) {
                $headCircumferenceItemByGroup[$item->groupCode] = $item->itemCode;
            }
        }

        return new RuleSet(
            versionKey: $versionKey,
            ageCalculationMode: $version->age_calculation_mode,
            ageGroups: $ageGroups,
            items: $items,
            areaRules: $areaRules,
            alternateAreaRules: $alternateAreaRules,
            globalRules: $globalRules,
            riskFactors: $riskFactors,
            headCircumferenceBands: $headCircumferenceBands,
            headCircumferenceItemByGroup: $headCircumferenceItemByGroup,
            groupCodeByLegacyId: $groupCodeByLegacyId,
            itemByLegacyId: $itemByLegacyId,
            riskFactorCodeByLegacyId: $riskFactorCodeByLegacyId,
        );
    }
}
