<?php

namespace Modules\Screening\Services\Scoring\Exceptions;

use RuntimeException;

/**
 * A `RuleSet` is either complete or absent (design §6): every official
 * group must have an area rule for each semaphore area it has items in, at
 * least one global rule ending in the mandatory catch-all, and a valid
 * head-circumference item designation. Also raised defensively at score
 * time if, against every seeded guarantee, no global rule matches
 * (REQ-SCO-007 -- the engine must throw, never default).
 */
class IncompleteRuleSetException extends RuntimeException {}
