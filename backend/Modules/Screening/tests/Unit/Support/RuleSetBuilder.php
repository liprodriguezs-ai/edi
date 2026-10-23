<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Unit\Support;

use Modules\Screening\Services\Scoring\AgeResolver;
use Modules\Screening\Services\Scoring\Values\AgeGroupDefinition;
use Modules\Screening\Services\Scoring\Values\AreaScoringRuleDefinition;
use Modules\Screening\Services\Scoring\Values\GlobalScoringRuleDefinition;
use Modules\Screening\Services\Scoring\Values\HeadCircumferenceBand;
use Modules\Screening\Services\Scoring\Values\ResultColorKey;
use Modules\Screening\Services\Scoring\Values\RiskFactorDefinition;
use Modules\Screening\Services\Scoring\Values\RuleSet;
use Modules\Screening\Services\Scoring\Values\ScreeningItemDefinition;

/**
 * Test-only fluent builder for small, hand-crafted RuleSets -- lets unit
 * tests exercise exact color combinations (e.g. the REQ-SCO-005 illustrative
 * scenarios) without depending on the real Instrument CSV content.
 */
final class RuleSetBuilder
{
    /** @var array<string, AgeGroupDefinition> */
    private array $ageGroups = [];

    /** @var array<string, ScreeningItemDefinition> */
    private array $items = [];

    /** @var array<string, AreaScoringRuleDefinition> */
    private array $areaRules = [];

    /** @var array<string, AreaScoringRuleDefinition> */
    private array $alternateAreaRules = [];

    /** @var array<string, array<int, GlobalScoringRuleDefinition>> */
    private array $globalRules = [];

    /** @var array<string, RiskFactorDefinition> */
    private array $riskFactors = [];

    /** @var array<string, HeadCircumferenceBand> */
    private array $headCircumferenceBands = [];

    /** @var array<string, string> */
    private array $headCircumferenceItemByGroup = [];

    /** @var array<int, string> */
    private array $groupCodeByLegacyId = [];

    /** @var array<int, array{group_code: string, item_code: string}> */
    private array $itemByLegacyId = [];

    /** @var array<int, string> */
    private array $riskFactorCodeByLegacyId = [];

    public function official(string $code, int $minAgeDays, int $maxAgeDays, ?string $alternateCode = null, ?int $legacyId = null): self
    {
        $this->ageGroups[$code] = new AgeGroupDefinition($code, false, $minAgeDays, $maxAgeDays, $alternateCode, $legacyId);
        if ($legacyId !== null) {
            $this->groupCodeByLegacyId[$legacyId] = $code;
        }

        return $this;
    }

    public function alternate(string $code, ?int $legacyId = null): self
    {
        $this->ageGroups[$code] = new AgeGroupDefinition($code, true, null, null, null, $legacyId);
        if ($legacyId !== null) {
            $this->groupCodeByLegacyId[$legacyId] = $code;
        }

        return $this;
    }

    /**
     * @param  array<string, string>  $optionColorByLabel
     */
    public function item(
        string $groupCode,
        string $itemCode,
        string $areaCode,
        array $optionColorByLabel,
        int $sortOrder = 1,
        ?int $legacyId = null,
    ): self {
        $item = new ScreeningItemDefinition($groupCode, $itemCode, $areaCode, $optionColorByLabel, $sortOrder, $legacyId);
        $this->items["{$groupCode}/{$itemCode}"] = $item;

        if ($legacyId !== null) {
            $this->itemByLegacyId[$legacyId] = ['group_code' => $groupCode, 'item_code' => $itemCode];
        }

        return $this;
    }

    public function areaRule(
        string $groupCode,
        string $areaCode,
        int $itemCount,
        int $redThreshold,
        ?int $greenThreshold,
        bool $allowsYellow,
    ): self {
        $this->areaRules["{$groupCode}/{$areaCode}"] = new AreaScoringRuleDefinition(
            $groupCode, $areaCode, $itemCount, $redThreshold, $greenThreshold, $allowsYellow
        );

        return $this;
    }

    public function alternateAreaRule(
        string $groupCode,
        string $areaCode,
        int $itemCount,
        int $redThreshold,
        ?int $greenThreshold,
        bool $allowsYellow,
    ): self {
        $this->alternateAreaRules["{$groupCode}/{$areaCode}"] = new AreaScoringRuleDefinition(
            $groupCode, $areaCode, $itemCount, $redThreshold, $greenThreshold, $allowsYellow
        );

        return $this;
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    public function globalRule(string $groupCode, int $sequence, array $condition, string $colorKey, string $messageKey = 'test'): self
    {
        $this->globalRules[$groupCode][] = new GlobalScoringRuleDefinition($groupCode, $sequence, $condition, $colorKey, $messageKey);

        return $this;
    }

    /**
     * Adds the mandatory trailing catch-all ({"all":[]} -> green) at a high
     * sequence number, so official groups pass RuleSet's completeness check.
     */
    public function withCatchAll(string $groupCode, int $sequence = 9999): self
    {
        return $this->globalRule($groupCode, $sequence, ['all' => []], ResultColorKey::GREEN, 'green');
    }

    public function riskFactor(string $code, ?string $autoDerivationKey = null, ?int $legacyId = null): self
    {
        $this->riskFactors[$code] = new RiskFactorDefinition($code, $autoDerivationKey, $legacyId);
        if ($legacyId !== null) {
            $this->riskFactorCodeByLegacyId[$legacyId] = $code;
        }

        return $this;
    }

    public function headCircumferenceBand(string $sexKey, int $ageMonths, int $minMm, int $maxMm): self
    {
        $this->headCircumferenceBands["{$sexKey}/{$ageMonths}"] = new HeadCircumferenceBand($sexKey, $ageMonths, $minMm, $maxMm);

        return $this;
    }

    public function headCircumferenceItem(string $groupCode, string $itemCode): self
    {
        $this->headCircumferenceItemByGroup[$groupCode] = $itemCode;

        return $this;
    }

    public function build(string $versionKey = 'test-version', string $ageCalculationMode = AgeResolver::MODE_THIRTY_DAY_MONTH): RuleSet
    {
        return new RuleSet(
            versionKey: $versionKey,
            ageCalculationMode: $ageCalculationMode,
            ageGroups: $this->ageGroups,
            items: $this->items,
            areaRules: $this->areaRules,
            alternateAreaRules: $this->alternateAreaRules,
            globalRules: $this->globalRules,
            riskFactors: $this->riskFactors,
            headCircumferenceBands: $this->headCircumferenceBands,
            headCircumferenceItemByGroup: $this->headCircumferenceItemByGroup,
            groupCodeByLegacyId: $this->groupCodeByLegacyId,
            itemByLegacyId: $this->itemByLegacyId,
            riskFactorCodeByLegacyId: $this->riskFactorCodeByLegacyId,
        );
    }
}
