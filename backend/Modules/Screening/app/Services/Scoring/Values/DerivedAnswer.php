<?php

namespace Modules\Screening\Services\Scoring\Values;

/**
 * One answer `AutoAnswerDeriver` computed from perinatal input (design
 * D8/D9), recorded on {@see ScoringResult} for explainability
 * (REQ-SCO-009). `value` is the boolean yes/no for a risk factor, or the
 * selected option label for the head-circumference item.
 */
final readonly class DerivedAnswer
{
    public const KIND_RISK_FACTOR = 'risk_factor';

    public const KIND_HEAD_CIRCUMFERENCE_ITEM = 'head_circumference_item';

    public function __construct(
        public string $kind,
        public string $code,
        public bool|string $value,
        public string $sourceField,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'code' => $this->code,
            'value' => $this->value,
            'source_field' => $this->sourceField,
        ];
    }
}
