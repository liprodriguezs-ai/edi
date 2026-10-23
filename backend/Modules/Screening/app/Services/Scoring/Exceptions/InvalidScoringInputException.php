<?php

namespace Modules\Screening\Services\Scoring\Exceptions;

use InvalidArgumentException;

/**
 * The caller built a structurally invalid `ScoringInput`: neither/both of
 * the `forGroup`/`forDates` paths (design D5), an unknown group/item/label
 * referenced from an answer, or an answer whose `ageGroupCode` is not the
 * applied group nor its alternate.
 */
class InvalidScoringInputException extends InvalidArgumentException {}
