<?php

namespace Modules\SubscriptionPlan\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Billing v2 plan×module offering fact: whether a billable module is free and
 * its trial-days override within a plan (billing_package), independent of
 * billing interval. One row per (billing_package_id, billable_module_id).
 * Interval-specific pricing (amount, currency, Stripe ids, is_active) stays
 * on the sibling PlanModulePrice — this table owns ONLY the two flags that
 * used to be duplicated (and could drift) per interval on that table.
 */
#[ObservedBy([GlobalObserver::class])]
class PlanModule extends BaseModel
{
    use CentralConnection;

    protected $table = 'plan_module';

    protected $fillable = [
        'billing_package_id',
        'billable_module_id',
        'is_free',
        'trial_days',
    ];

    protected function casts(): array
    {
        return [
            'is_free' => 'boolean',
            'trial_days' => 'integer',
        ];
    }

    /**
     * The plan this offering fact belongs to (a billing_package reused as a plan).
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
