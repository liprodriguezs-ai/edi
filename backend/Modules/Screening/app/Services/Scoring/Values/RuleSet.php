<?php

namespace Modules\Screening\Services\Scoring\Values;

use Modules\Screening\Services\Scoring\Exceptions\IncompleteRuleSetException;

/**
 * Immutable snapshot of one instrument version's scoring content (design D3
 * / §3), keyed by NATURAL keys throughout -- never UUIDs. Built exclusively
 * by the loaders in `Modules\Screening\Services\RuleSetLoading` (outside the
 * pure namespace); every collaborator in `Modules\Screening\Services\Scoring`
 * only ever reads from an already-built RuleSet.
 *
 * A RuleSet is either complete or absent (design §6): the constructor
 * validates official-group coverage (an area rule for every semaphore area
 * with items, at least one global rule ending in the mandatory green
 * catch-all) and throws {@see IncompleteRuleSetException} otherwise, so a
 * broken RuleSet can never reach the engine.
 */
final readonly class RuleSet
{
    /**
     * The one counter area (never area-scored, design REQ-SCO-005/006).
     */
    public const ALE_AREA_CODE = 'ALE';

    /**
     * @param  array<string, AgeGroupDefinition>  $ageGroups  code => definition (official + alternate, design §3)
     * @param  array<string, ScreeningItemDefinition>  $items  "{group_code}/{item_code}" => definition
     * @param  array<string, AreaScoringRuleDefinition>  $areaRules  "{group_code}/{area_code}" => definition (OFFICIAL groups only)
     * @param  array<string, AreaScoringRuleDefinition>  $alternateAreaRules  "{group_code}/{area_code}" => definition (ALTERNATE groups, record only -- design D4)
     * @param  array<string, array<int, GlobalScoringRuleDefinition>>  $globalRules  group_code => rules ordered by ascending sequence
     * @param  array<string, RiskFactorDefinition>  $riskFactors  code => definition
     * @param  array<string, HeadCircumferenceBand>  $headCircumferenceBands  "{sex_key}/{age_months}" => band
     * @param  array<string, string>  $headCircumferenceItemByGroup  group_code => item_code (design D10, absent for G15)
     * @param  array<int, string>  $groupCodeByLegacyId
     * @param  array<int, array{group_code: string, item_code: string}>  $itemByLegacyId
     * @param  array<int, string>  $riskFactorCodeByLegacyId
     */
    public function __construct(
        public string $versionKey,
        public string $ageCalculationMode,
        public array $ageGroups,
        public array $items,
        public array $areaRules,
        public array $alternateAreaRules,
        public array $globalRules,
        public array $riskFactors,
        public array $headCircumferenceBands,
        public array $headCircumferenceItemByGroup,
        public array $groupCodeByLegacyId,
        public array $itemByLegacyId,
        public array $riskFactorCodeByLegacyId,
    ) {
        $this->assertComplete();
    }

    public function group(string $code): AgeGroupDefinition
    {
        return $this->ageGroups[$code]
            ?? throw new IncompleteRuleSetException("Unknown age_group code '{$code}' in RuleSet '{$this->versionKey}'.");
    }

    /**
     * @return array<string, AgeGroupDefinition> code => definition, official groups only
     */
    public function officialGroups(): array
    {
        return array_filter($this->ageGroups, fn (AgeGroupDefinition $g) => ! $g->isAlternate);
    }

    public function item(string $groupCode, string $itemCode): ScreeningItemDefinition
    {
        $key = "{$groupCode}/{$itemCode}";

        return $this->items[$key]
            ?? throw new IncompleteRuleSetException("Unknown screening item '{$key}' in RuleSet '{$this->versionKey}'.");
    }

    public function hasItem(string $groupCode, string $itemCode): bool
    {
        return isset($this->items["{$groupCode}/{$itemCode}"]);
    }

    /**
     * @return array<int, ScreeningItemDefinition> items of one (group, area) pair, in sort_order
     */
    public function itemsInGroupArea(string $groupCode, string $areaCode): array
    {
        $matched = array_values(array_filter(
            $this->items,
            fn (ScreeningItemDefinition $i) => $i->groupCode === $groupCode && $i->areaCode === $areaCode
        ));

        usort($matched, fn (ScreeningItemDefinition $a, ScreeningItemDefinition $b) => $a->sortOrder <=> $b->sortOrder);

        return $matched;
    }

    /**
     * Distinct semaphore area codes (excludes the ALE counter) with at
     * least one item in the given group -- the areas `AreaScorer` iterates.
     *
     * @return array<int, string>
     */
    public function semaphoreAreaCodesInGroup(string $groupCode): array
    {
        $codes = [];
        foreach ($this->items as $item) {
            if ($item->groupCode === $groupCode && $item->areaCode !== self::ALE_AREA_CODE) {
                $codes[$item->areaCode] = true;
            }
        }

        return array_keys($codes);
    }

    public function areaRule(string $groupCode, string $areaCode): AreaScoringRuleDefinition
    {
        $key = "{$groupCode}/{$areaCode}";

        return $this->areaRules[$key]
            ?? throw new IncompleteRuleSetException("Missing area_scoring_rule for '{$key}' in RuleSet '{$this->versionKey}'.");
    }

    /**
     * @return array<int, GlobalScoringRuleDefinition> ordered by ascending sequence
     */
    public function globalRulesFor(string $groupCode): array
    {
        return $this->globalRules[$groupCode]
            ?? throw new IncompleteRuleSetException("No global_scoring_rule rows for group '{$groupCode}' in RuleSet '{$this->versionKey}'.");
    }

    public function riskFactor(string $code): RiskFactorDefinition
    {
        return $this->riskFactors[$code]
            ?? throw new IncompleteRuleSetException("Unknown risk_factor code '{$code}' in RuleSet '{$this->versionKey}'.");
    }

    public function riskFactorByAutoDerivationKey(string $autoDerivationKey): ?RiskFactorDefinition
    {
        foreach ($this->riskFactors as $factor) {
            if ($factor->autoDerivationKey === $autoDerivationKey) {
                return $factor;
            }
        }

        return null;
    }

    public function headCircumferenceBand(string $sexKey, int $ageMonths): ?HeadCircumferenceBand
    {
        return $this->headCircumferenceBands["{$sexKey}/{$ageMonths}"] ?? null;
    }

    public function headCircumferenceItemCodeFor(string $groupCode): ?string
    {
        return $this->headCircumferenceItemByGroup[$groupCode] ?? null;
    }

    public function groupCodeForLegacyId(int $legacyId): ?string
    {
        return $this->groupCodeByLegacyId[$legacyId] ?? null;
    }

    /**
     * @return array{group_code: string, item_code: string}|null
     */
    public function itemForLegacyId(int $legacyId): ?array
    {
        return $this->itemByLegacyId[$legacyId] ?? null;
    }

    public function riskFactorCodeForLegacyId(int $legacyId): ?string
    {
        return $this->riskFactorCodeByLegacyId[$legacyId] ?? null;
    }

    /**
     * Canonical, natural-key-sorted array form used by the loader parity
     * test (REQ-SCO-002) -- two RuleSets built from different sources are
     * equal iff this array is identical.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $ageGroups = $this->ageGroups;
        ksort($ageGroups);

        $items = $this->items;
        ksort($items);

        $areaRules = $this->areaRules;
        ksort($areaRules);

        $alternateAreaRules = $this->alternateAreaRules;
        ksort($alternateAreaRules);

        $globalRules = $this->globalRules;
        ksort($globalRules);

        $riskFactors = $this->riskFactors;
        ksort($riskFactors);

        $headCircumferenceBands = $this->headCircumferenceBands;
        ksort($headCircumferenceBands);

        $headCircumferenceItemByGroup = $this->headCircumferenceItemByGroup;
        ksort($headCircumferenceItemByGroup);

        return [
            'version_key' => $this->versionKey,
            'age_calculation_mode' => $this->ageCalculationMode,
            'age_groups' => array_map(fn (AgeGroupDefinition $g) => [
                'code' => $g->code,
                'is_alternate' => $g->isAlternate,
                'min_age_days' => $g->minAgeDays,
                'max_age_days' => $g->maxAgeDays,
                'alternate_group_code' => $g->alternateGroupCode,
            ], $ageGroups),
            'items' => array_map(fn (ScreeningItemDefinition $i) => [
                'group_code' => $i->groupCode,
                'item_code' => $i->itemCode,
                'area_code' => $i->areaCode,
                'options' => $i->optionColorByLabel,
                'sort_order' => $i->sortOrder,
            ], $items),
            'area_rules' => array_map(fn (AreaScoringRuleDefinition $r) => [
                'group_code' => $r->groupCode,
                'area_code' => $r->areaCode,
                'item_count' => $r->itemCount,
                'red_threshold' => $r->redThreshold,
                'green_threshold' => $r->greenThreshold,
                'allows_yellow' => $r->allowsYellow,
            ], $areaRules),
            'alternate_area_rules' => array_map(fn (AreaScoringRuleDefinition $r) => [
                'group_code' => $r->groupCode,
                'area_code' => $r->areaCode,
                'item_count' => $r->itemCount,
                'red_threshold' => $r->redThreshold,
                'green_threshold' => $r->greenThreshold,
                'allows_yellow' => $r->allowsYellow,
            ], $alternateAreaRules),
            'global_rules' => array_map(
                fn (array $rules) => array_map(fn (GlobalScoringRuleDefinition $r) => [
                    'sequence' => $r->sequence,
                    'condition' => $r->condition,
                    'color_key' => $r->colorKey,
                    'message_key' => $r->messageKey,
                ], $rules),
                $globalRules
            ),
            'risk_factors' => array_map(fn (RiskFactorDefinition $r) => [
                'code' => $r->code,
                'auto_derivation_key' => $r->autoDerivationKey,
            ], $riskFactors),
            'head_circumference_bands' => array_map(fn (HeadCircumferenceBand $b) => [
                'sex_key' => $b->sexKey,
                'age_months' => $b->ageMonths,
                'min_mm' => $b->minMm,
                'max_mm' => $b->maxMm,
            ], $headCircumferenceBands),
            'head_circumference_item_by_group' => $headCircumferenceItemByGroup,
        ];
    }

    /**
     * Design §6 build-time validation: a RuleSet is complete or absent.
     */
    private function assertComplete(): void
    {
        foreach ($this->officialGroups() as $group) {
            foreach ($this->semaphoreAreaCodesInGroup($group->code) as $areaCode) {
                if (! isset($this->areaRules["{$group->code}/{$areaCode}"])) {
                    throw new IncompleteRuleSetException(
                        "Official group '{$group->code}' has items in area '{$areaCode}' but no area_scoring_rule."
                    );
                }
            }

            $rules = $this->globalRules[$group->code] ?? [];
            if ($rules === []) {
                throw new IncompleteRuleSetException("Official group '{$group->code}' has no global_scoring_rule rows.");
            }

            $last = $rules[count($rules) - 1];
            if ($last->condition !== ['all' => []] || $last->colorKey !== ResultColorKey::GREEN) {
                throw new IncompleteRuleSetException(
                    "Official group '{$group->code}' does not end in the mandatory green catch-all rule."
                );
            }
        }

        foreach ($this->headCircumferenceItemByGroup as $groupCode => $itemCode) {
            if (! $this->hasItem($groupCode, $itemCode)) {
                throw new IncompleteRuleSetException(
                    "Head-circumference item designation '{$groupCode}/{$itemCode}' does not exist."
                );
            }

            $item = $this->item($groupCode, $itemCode);
            $colors = array_values($item->optionColorByLabel);
            sort($colors);
            if ($colors !== [ResultColorKey::GREEN, ResultColorKey::RED]) {
                throw new IncompleteRuleSetException(
                    "Head-circumference item '{$groupCode}/{$itemCode}' must have exactly a green and a red option."
                );
            }
        }
    }
}
