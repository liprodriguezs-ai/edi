<?php

namespace Modules\Screening\Services\Scoring\Values;

/**
 * One answered reactivo. `ageGroupCode` names the group the ANSWERED item
 * actually belongs to -- the applied official group for own items, or the
 * alternate group's code for evaluación alterna answers (design §3). Never
 * the item code alone: item codes are opaque and only unique within a group
 * (instrument design D10).
 */
final readonly class ItemAnswer
{
    public function __construct(
        public string $ageGroupCode,
        public string $itemCode,
        public string $selectedLabel,
    ) {}
}
