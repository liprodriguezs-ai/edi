<?php

namespace Modules\Screening\Services\Scoring\Values;

/**
 * One reactivo inside a {@see RuleSet} (design §3). `optionColorByLabel`
 * maps the verbatim answer label (e.g. `SI`/`NO`) to the color key it
 * produces -- labels are source data, never parsed for meaning (instrument
 * design D10/D11).
 */
final readonly class ScreeningItemDefinition
{
    /**
     * @param  array<string, string>  $optionColorByLabel  label => color key
     */
    public function __construct(
        public string $groupCode,
        public string $itemCode,
        public string $areaCode,
        public array $optionColorByLabel,
        public int $sortOrder,
        public ?int $legacyId,
    ) {}

    public function colorForLabel(string $label): string
    {
        return $this->optionColorByLabel[$label]
            ?? throw new \InvalidArgumentException(
                "Item '{$this->groupCode}/{$this->itemCode}' has no option labeled '{$label}'."
            );
    }

    /**
     * The option label whose color is $colorKey (design D9 -- options are
     * selected BY COLOR, never by label).
     */
    public function labelForColor(string $colorKey): string
    {
        foreach ($this->optionColorByLabel as $label => $color) {
            if ($color === $colorKey) {
                return $label;
            }
        }

        throw new \InvalidArgumentException(
            "Item '{$this->groupCode}/{$this->itemCode}' has no option colored '{$colorKey}'."
        );
    }
}
