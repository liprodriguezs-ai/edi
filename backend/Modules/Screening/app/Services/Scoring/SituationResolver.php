<?php

namespace Modules\Screening\Services\Scoring;

use InvalidArgumentException;
use Modules\Screening\Services\Scoring\Values\ResultColorKey;
use Modules\Screening\Services\Scoring\Values\SituationKey;
use Modules\Screening\Services\Scoring\Values\SituationOutcome;

/**
 * Classifies the current global color against the previous one into the
 * seven situation states (design D12 / REQ-SCO-008). `unchanged` and
 * `worsened` are kept distinct even though the legacy export conflates them
 * under `sis_code = 6`.
 */
final class SituationResolver
{
    public function resolveSituation(?string $previousColorKey, string $currentColorKey): SituationOutcome
    {
        if ($previousColorKey === null) {
            return match ($currentColorKey) {
                ResultColorKey::GREEN => new SituationOutcome(SituationKey::FIRST_TIME_GREEN, 1),
                ResultColorKey::YELLOW => new SituationOutcome(SituationKey::FIRST_TIME_YELLOW, 2),
                ResultColorKey::RED => new SituationOutcome(SituationKey::FIRST_TIME_RED, 3),
                default => throw new InvalidArgumentException("Unknown result color key '{$currentColorKey}'."),
            };
        }

        if ($previousColorKey === ResultColorKey::YELLOW && $currentColorKey === ResultColorKey::GREEN) {
            return new SituationOutcome(SituationKey::RECOVERED_FROM_YELLOW, 4);
        }

        if ($previousColorKey === ResultColorKey::RED
            && in_array($currentColorKey, [ResultColorKey::YELLOW, ResultColorKey::GREEN], true)) {
            return new SituationOutcome(SituationKey::RECOVERED_FROM_RED, 5);
        }

        if ($currentColorKey === $previousColorKey) {
            return new SituationOutcome(SituationKey::UNCHANGED, 6);
        }

        if (ResultColorKey::severity($currentColorKey) > ResultColorKey::severity($previousColorKey)) {
            return new SituationOutcome(SituationKey::WORSENED, 6);
        }

        throw new InvalidArgumentException(
            "Unhandled situation transition from '{$previousColorKey}' to '{$currentColorKey}'."
        );
    }
}
