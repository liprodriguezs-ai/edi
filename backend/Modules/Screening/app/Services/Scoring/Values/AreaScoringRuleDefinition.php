<?php

namespace Modules\Screening\Services\Scoring\Values;

/**
 * One area_scoring_rule row inside a {@see RuleSet} (design §3/D4). Only the
 * rows belonging to OFFICIAL groups are ever consulted by `AreaScorer`; the
 * alternate rows are loaded as `RuleSet::$alternateAreaRules` for record
 * only (instrument design §14 -- Model A pooling, NOT a separate rule path).
 */
final readonly class AreaScoringRuleDefinition
{
    public function __construct(
        public string $groupCode,
        public string $areaCode,
        public int $itemCount,
        public int $redThreshold,
        public ?int $greenThreshold,
        public bool $allowsYellow,
    ) {}

    /**
     * The 4-branch contract (REQ-SCO-005 / design D4): reds first, then
     * greens (only when a green threshold is defined), then yellow as the
     * catch-all when allowed, else green.
     */
    public function colorFor(int $reds, int $yellows, int $greens): string
    {
        if ($reds >= $this->redThreshold) {
            return ResultColorKey::RED;
        }

        if ($this->greenThreshold !== null && $greens >= $this->greenThreshold) {
            return ResultColorKey::GREEN;
        }

        if ($this->allowsYellow) {
            return ResultColorKey::YELLOW;
        }

        return ResultColorKey::GREEN;
    }
}
