<?php

namespace App\Models;

use App\Casts\AppTimezoneCast;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Cashier\Subscription as CashierSubscription;

class Subscription extends CashierSubscription
{
    use HasUuids;

    /**
     * Stripe-derived timestamps (`trial_ends_at`, `ends_at`) are normalized
     * to app timezone — see App\Casts\AppTimezoneCast. `currentPeriodEnd()`
     * reads directly from Stripe and is unaffected.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'trial_ends_at' => AppTimezoneCast::class,
            'ends_at' => AppTimezoneCast::class,
        ]);
    }
}
