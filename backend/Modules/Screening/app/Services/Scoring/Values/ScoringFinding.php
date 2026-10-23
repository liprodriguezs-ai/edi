<?php

namespace Modules\Screening\Services\Scoring\Values;

/**
 * A CLOSED clinical-trace note (design D14): the engine only ever emits one
 * of the five keys below, each with a small typed context array. Anything
 * that is not one of these five is a caller ERROR and must raise a typed
 * exception instead -- findings are never a dumping ground for unexpected
 * input.
 */
final readonly class ScoringFinding
{
    public const AGE_GROUP_SUBSTITUTED_BY_CORRECTED_AGE = 'age_group_substituted_by_corrected_age';

    public const UNKNOWN_PERINATAL_INPUT = 'unknown_perinatal_input';

    public const AUTO_ANSWER_OVERRODE_MANUAL = 'auto_answer_overrode_manual';

    public const UNANSWERED_ITEM = 'unanswered_item';

    public const MISSING_PERCENTILE_REFERENCE = 'missing_percentile_reference';

    /**
     * @var array<string, mixed>
     */
    public array $context;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $key,
        array $context = [],
    ) {
        $this->context = $context;
    }

    /**
     * @return array{key: string, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'context' => $this->context,
        ];
    }
}
