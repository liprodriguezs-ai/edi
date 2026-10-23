<?php

namespace Modules\Screening\Services\Scoring\Values;

use Modules\Screening\Services\Scoring\SituationResolver;

/**
 * Outcome of {@see SituationResolver}
 * (design D12 / REQ-SCO-008): the situation key (one of {@see SituationKey}'s
 * seven states) plus the legacy `sis_code` (1-6) it maps to for the official
 * export.
 */
final readonly class SituationOutcome
{
    public function __construct(
        public string $situationKey,
        public int $sisCode,
    ) {}

    /**
     * @return array{situation_key: string, sis_code: int}
     */
    public function toArray(): array
    {
        return [
            'situation_key' => $this->situationKey,
            'sis_code' => $this->sisCode,
        ];
    }
}
