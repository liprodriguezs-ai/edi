<?php

namespace Modules\Screening\Services\RuleSetLoading;

use Modules\Screening\Services\Scoring\Values\AgeGroupDefinition;
use Modules\Screening\Services\Scoring\Values\AreaScoringRuleDefinition;
use Modules\Screening\Services\Scoring\Values\GlobalScoringRuleDefinition;
use Modules\Screening\Services\Scoring\Values\HeadCircumferenceBand;
use Modules\Screening\Services\Scoring\Values\RiskFactorDefinition;
use Modules\Screening\Services\Scoring\Values\RuleSet;
use Modules\Screening\Services\Scoring\Values\ScreeningItemDefinition;
use RuntimeException;

/**
 * Builds a {@see RuleSet} straight from a version's committed seed CSV
 * directory (`backend/Modules/Instrument/database/seeders/csv/{version-key}/`)
 * with ZERO database access (design D3/§6, REQ-SCO-002). Deliberately
 * framework-free -- no Laravel helper, no Eloquent -- so it runs inside a
 * plain `PHPUnit\Framework\TestCase` with no application bootstrap (the
 * oracle harness, REQ-SCO-010).
 *
 * Path resolution is a fixed relative walk from this file to the sibling
 * `Instrument` module (Q8: accepted test-only cross-module coupling to the
 * single source of truth; the loader parity test in
 * `tests/Feature/RuleSetParityTest.php` guards equivalence with the
 * database-backed loader).
 *
 * Load order matters for FK-style natural-key resolution: age_group before
 * screening_item, screening_item before area/global rules (D10 head-
 * circumference item designation), risk_factor and
 * head_circumference_reference are independent.
 */
final class CsvRuleSetLoader
{
    private const AREA_EN = 'EN';

    private const HEAD_CIRCUMFERENCE_SORT_ORDER = 3;

    public function build(string $versionKey, string $ageCalculationMode): RuleSet
    {
        $csvDir = $this->csvDirectoryFor($versionKey);

        $ageGroups = $this->loadAgeGroups($csvDir.'/age_group.csv');
        $groupCodeByLegacyId = $this->groupCodeByLegacyId($ageGroups);

        [$items, $itemByLegacyId] = $this->loadScreeningItems($csvDir.'/screening_item.csv');

        [$areaRules, $alternateAreaRules] = $this->loadAreaScoringRules($csvDir.'/area_scoring_rule.csv', $ageGroups);

        $globalRules = $this->loadGlobalScoringRules($csvDir.'/global_scoring_rule.csv');

        [$riskFactors, $riskFactorCodeByLegacyId] = $this->loadRiskFactors($csvDir.'/risk_factor.csv');

        $headCircumferenceBands = $this->loadHeadCircumferenceReferences($csvDir.'/head_circumference_reference.csv');

        $headCircumferenceItemByGroup = $this->designateHeadCircumferenceItems($items);

        return new RuleSet(
            versionKey: $versionKey,
            ageCalculationMode: $ageCalculationMode,
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

    private function csvDirectoryFor(string $versionKey): string
    {
        // this file: Modules/Screening/app/Services/RuleSetLoading/CsvRuleSetLoader.php
        // 4 levels up: Modules/
        $modulesDir = dirname(__DIR__, 4);

        return "{$modulesDir}/Instrument/database/seeders/csv/{$versionKey}";
    }

    // ── age_group ────────────────────────────────────────────────────────

    /**
     * @return array<string, AgeGroupDefinition>
     */
    private function loadAgeGroups(string $path): array
    {
        $ageGroups = [];

        foreach (self::readCsv($path) as $row) {
            $ageGroups[$row['code']] = new AgeGroupDefinition(
                code: $row['code'],
                isAlternate: $row['is_alternate'] === '1',
                minAgeDays: self::nullableInt($row['min_age_days']),
                maxAgeDays: self::nullableInt($row['max_age_days']),
                alternateGroupCode: $row['alternate_group_code'] !== '' ? $row['alternate_group_code'] : null,
                legacyId: self::nullableInt($row['legacy_id']),
            );
        }

        return $ageGroups;
    }

    /**
     * @param  array<string, AgeGroupDefinition>  $ageGroups
     * @return array<int, string>
     */
    private function groupCodeByLegacyId(array $ageGroups): array
    {
        $map = [];
        foreach ($ageGroups as $group) {
            if ($group->legacyId !== null) {
                $map[$group->legacyId] = $group->code;
            }
        }

        return $map;
    }

    // ── screening_item (+ 2 embedded options) ───────────────────────────

    /**
     * @return array{0: array<string, ScreeningItemDefinition>, 1: array<int, array{group_code: string, item_code: string}>}
     */
    private function loadScreeningItems(string $path): array
    {
        $items = [];
        $itemByLegacyId = [];

        foreach (self::readCsv($path) as $row) {
            $item = new ScreeningItemDefinition(
                groupCode: $row['group_code'],
                itemCode: $row['code'],
                areaCode: $row['area_code'],
                optionColorByLabel: [
                    $row['option_1_label'] => $row['option_1_color'],
                    $row['option_2_label'] => $row['option_2_color'],
                ],
                sortOrder: (int) $row['sort_order'],
                legacyId: self::nullableInt($row['legacy_id']),
            );

            $items["{$item->groupCode}/{$item->itemCode}"] = $item;

            if ($item->legacyId !== null) {
                $itemByLegacyId[$item->legacyId] = ['group_code' => $item->groupCode, 'item_code' => $item->itemCode];
            }
        }

        return [$items, $itemByLegacyId];
    }

    // ── area_scoring_rule (split official vs alternate, design D4) ─────

    /**
     * @param  array<string, AgeGroupDefinition>  $ageGroups
     * @return array{0: array<string, AreaScoringRuleDefinition>, 1: array<string, AreaScoringRuleDefinition>}
     */
    private function loadAreaScoringRules(string $path, array $ageGroups): array
    {
        $official = [];
        $alternate = [];

        foreach (self::readCsv($path) as $row) {
            $group = $ageGroups[$row['group_code']]
                ?? throw new RuntimeException("Unknown age_group code '{$row['group_code']}' in area_scoring_rule.csv");

            $rule = new AreaScoringRuleDefinition(
                groupCode: $row['group_code'],
                areaCode: $row['area_code'],
                itemCount: (int) $row['item_count'],
                redThreshold: (int) $row['red_threshold'],
                greenThreshold: self::nullableInt($row['green_threshold']),
                allowsYellow: $row['allows_yellow'] === '1',
            );

            $key = "{$rule->groupCode}/{$rule->areaCode}";

            if ($group->isAlternate) {
                $alternate[$key] = $rule;
            } else {
                $official[$key] = $rule;
            }
        }

        return [$official, $alternate];
    }

    // ── global_scoring_rule ──────────────────────────────────────────────

    /**
     * @return array<string, array<int, GlobalScoringRuleDefinition>> group_code => rules ordered by sequence
     */
    private function loadGlobalScoringRules(string $path): array
    {
        $byGroup = [];

        foreach (self::readCsv($path) as $row) {
            $byGroup[$row['group_code']][] = new GlobalScoringRuleDefinition(
                groupCode: $row['group_code'],
                sequence: (int) $row['sequence'],
                condition: json_decode($row['condition'], true, 512, JSON_THROW_ON_ERROR),
                colorKey: $row['result_color_key'],
                messageKey: $row['message_key'],
            );
        }

        foreach ($byGroup as &$rules) {
            usort($rules, fn (GlobalScoringRuleDefinition $a, GlobalScoringRuleDefinition $b) => $a->sequence <=> $b->sequence);
        }
        unset($rules);

        return $byGroup;
    }

    // ── risk_factor ──────────────────────────────────────────────────────

    /**
     * @return array{0: array<string, RiskFactorDefinition>, 1: array<int, string>}
     */
    private function loadRiskFactors(string $path): array
    {
        $riskFactors = [];
        $byLegacyId = [];

        foreach (self::readCsv($path) as $row) {
            $factor = new RiskFactorDefinition(
                code: $row['code'],
                autoDerivationKey: $row['auto_derivation_key'] !== '' ? $row['auto_derivation_key'] : null,
                legacyId: self::nullableInt($row['legacy_id']),
            );

            $riskFactors[$factor->code] = $factor;

            if ($factor->legacyId !== null) {
                $byLegacyId[$factor->legacyId] = $factor->code;
            }
        }

        return [$riskFactors, $byLegacyId];
    }

    // ── head_circumference_reference ────────────────────────────────────

    /**
     * @return array<string, HeadCircumferenceBand>
     */
    private function loadHeadCircumferenceReferences(string $path): array
    {
        $bands = [];

        foreach (self::readCsv($path) as $row) {
            $band = new HeadCircumferenceBand(
                sexKey: $row['sex_key'],
                ageMonths: (int) $row['age_months'],
                minMm: (int) $row['min_mm'],
                maxMm: (int) $row['max_mm'],
            );

            $bands["{$band->sexKey}/{$band->ageMonths}"] = $band;
        }

        return $bands;
    }

    // ── head-circumference item designation (design D10) ───────────────

    /**
     * @param  array<string, ScreeningItemDefinition>  $items
     * @return array<string, string> group_code => item_code
     */
    private function designateHeadCircumferenceItems(array $items): array
    {
        $byGroup = [];

        foreach ($items as $item) {
            if ($item->areaCode === self::AREA_EN && $item->sortOrder === self::HEAD_CIRCUMFERENCE_SORT_ORDER) {
                $byGroup[$item->groupCode] = $item->itemCode;
            }
        }

        return $byGroup;
    }

    // ── CSV reading (fopen/fgetcsv -- handles embedded newlines inside
    //    quoted fields, same gotcha the Instrument seeder hit) ──────────

    /**
     * @return array<int, array<string, string>>
     */
    private static function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open CSV: {$path}");
        }

        $header = fgetcsv($handle, escape: '\\');
        if ($header === false) {
            fclose($handle);

            return [];
        }

        $rows = [];
        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            if (count($row) < count($header)) {
                continue;
            }
            $rows[] = array_combine($header, $row);
        }

        fclose($handle);

        return $rows;
    }

    private static function nullableInt(string $value): ?int
    {
        return $value === '' ? null : (int) $value;
    }
}
