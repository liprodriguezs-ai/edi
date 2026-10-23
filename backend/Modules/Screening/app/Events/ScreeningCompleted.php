<?php

declare(strict_types=1);

namespace Modules\Screening\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Screening\Models\Screening;

/**
 * REQ-SCR-016 — dispatched by ScreeningLifecycleService::complete(), inside
 * the completion transaction, after the status flip to `completed`. Fires at
 * most once per screening through the API (the frozen guard rejects a second
 * `complete`). Consumed synchronously by
 * Modules\FollowUp\Listeners\CreateFollowUpsFromScreening (design D1): a
 * listener failure rolls back the completion, so a completed screening never
 * exists without its conduct.
 */
final class ScreeningCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly Screening $screening,
    ) {}
}
