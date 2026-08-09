<?php

namespace Modules\Chat\Exceptions;

use InvalidArgumentException;

/**
 * Channel access violation (non-member interaction, read-only channel write,
 * join-eligibility failure). Extends InvalidArgumentException so existing
 * service-level callers/tests that catch the base type keep working; HTTP
 * controllers map it to 403.
 */
class ChatAccessDeniedException extends InvalidArgumentException {}
