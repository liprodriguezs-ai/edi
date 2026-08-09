<?php

namespace Modules\SubscriptionPlan\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Billing v2 pricing matrix cell: the fixed price of a billable module within a
 * plan (billing_package) for a given interval. Money is stored in minor units
 * (cents) in `unit_amount`; the cast is `integer` to mirror the sibling
 * BillingPackagePrice.unit_amount and keep the JSON contract on raw integers.
 *
 * is_free/trial_days are NOT columns here — they are (plan, module) facts
 * owned by the sibling PlanModule table, independent of interval. See
 * PlanModule for the flags this row's plan/module pair carries.
 */
#[ObservedBy([GlobalObserver::class])]
class PlanModulePrice extends BaseModel
{
    use CentralConnection;

    protected $table = 'plan_module_price';

    public const INTERVAL_MONTH = 'month';

    public const INTERVAL_YEAR = 'year';

    public const INTERVALS = [self::INTERVAL_MONTH, self::INTERVAL_YEAR];

    protected $fillable = [
        'billing_package_id',
        'billable_module_id',
        'interval',
        'unit_amount',
        'currency',
        'stripe_price_id',
        'stripe_product_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'unit_amount' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The plan this price belongs to (a billing_package reused as a plan).
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(BillingPackage::class, 'billing_package_id');
    }

    public function billable_module(): BelongsTo
    {
        return $this->belongsTo(BillableModule::class, 'billable_module_id');
    }
}
