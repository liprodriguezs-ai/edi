<?php

namespace Modules\Screening\Services\Scoring\Exceptions;

use RuntimeException;

/**
 * The screening cannot be scored for this child at all: chronological age
 * outside [30, 2159] days, or a corrected age below 30 days (design D7,
 * open decision Q3). The caller must refuse loudly, never clamp to a
 * neighboring group.
 */
class ScreeningNotApplicableException extends RuntimeException {}
