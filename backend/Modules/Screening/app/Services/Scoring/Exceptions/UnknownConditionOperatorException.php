<?php

namespace Modules\Screening\Services\Scoring\Exceptions;

use RuntimeException;

/**
 * `ConditionEvaluator` refuses to evaluate anything outside the closed
 * 6-operator grammar (design D13 / instrument design §5.4): an unknown
 * operator key, an unknown SET name, or an unknown color key. Never a
 * silent `false` (REQ-SCO-007).
 */
class UnknownConditionOperatorException extends RuntimeException {}
