<?php

namespace Modules\Chat\Exceptions;

use InvalidArgumentException;

/**
 * Per-user message rate limit exceeded (spec §13.4.9 — 30 msg/min).
 * Extends InvalidArgumentException for backwards compatibility with callers
 * catching the base type; HTTP controllers map it to 429.
 */
class ChatRateLimitException extends InvalidArgumentException {}
