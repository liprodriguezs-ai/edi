<?php

namespace Modules\Screening\Services\Scoring\Values;

/**
 * The seven situation states (design D12 / REQ-SCO-008). String constants,
 * never a PHP native enum (project hard rule 4). The legacy `sis_code`
 * export conflates `unchanged` and `worsened` under code 6 -- this model
 * keeps them distinct while still exposing that code via
 * {@see SituationOutcome::$sisCode}.
 */
final class SituationKey
{
    public const FIRST_TIME_GREEN = 'first_time_green';

    public const FIRST_TIME_YELLOW = 'first_time_yellow';

    public const FIRST_TIME_RED = 'first_time_red';

    public const RECOVERED_FROM_YELLOW = 'recovered_from_yellow';

    public const RECOVERED_FROM_RED = 'recovered_from_red';

    public const UNCHANGED = 'unchanged';

    public const WORSENED = 'worsened';

    private function __construct() {}
}
