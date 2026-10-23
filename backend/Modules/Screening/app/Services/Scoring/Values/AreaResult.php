<?php

namespace Modules\Screening\Services\Scoring\Values;

/**
 * `AreaScorer`'s output for one semaphore area (design §3/§9): the pooled
 * counts (own + answered alternate, design D4) and the applied OFFICIAL
 * group's thresholds, so a clinician can reconstruct the color without
 * re-running the engine (REQ-SCO-009).
 */
final readonly class AreaResult
{
    public function __construct(
        public string $areaCode,
        public string $colorKey,
        public int $reds,
        public int $yellows,
        public int $greens,
        public int $answeredOwn,
        public int $answeredAlternate,
        public int $redThreshold,
        public ?int $greenThreshold,
        public bool $allowsYellow,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'area_code' => $this->areaCode,
            'color_key' => $this->colorKey,
            'reds' => $this->reds,
            'yellows' => $this->yellows,
            'greens' => $this->greens,
            'answered_own' => $this->answeredOwn,
            'answered_alternate' => $this->answeredAlternate,
            'red_threshold' => $this->redThreshold,
            'green_threshold' => $this->greenThreshold,
            'allows_yellow' => $this->allowsYellow,
        ];
    }
}
