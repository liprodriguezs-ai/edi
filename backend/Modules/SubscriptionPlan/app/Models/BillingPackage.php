<?php

namespace Modules\SubscriptionPlan\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\HasMany;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Central billing v2 PLAN (basico..premium) shown on the landing pricing page.
 * No tenant_id: the catalog is identical for every tenant. The price of each
 * billable module inside a plan lives in plan_module_price (the matrix cells).
 */
#[ObservedBy([GlobalObserver::class])]
class BillingPackage extends BaseModel
{
    use CentralConnection;

    protected $table = 'billing_package';

    protected $fillable = [
        'key',
        'name',
        'description',
        'sort_order',
        'is_active',
        'is_public',
        'suggested_unit_min',
        'suggested_unit_max',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'suggested_unit_min' => 'integer',
            'suggested_unit_max' => 'integer',
        ];
    }

    /**
     * Billing v2 plan×module price rows of this plan (the matrix cells).
     * PlanPricingService resolves plans via whereHas('plan_prices').
     */
    public function plan_prices(): HasMany
    {
        return $this->hasMany(PlanModulePrice::class);
    }

    /**
     * Billing v2 plan×module offering facts (is_free/trial_days) of this
     * plan, independent of interval. See PlanModule.
     */
    public function plan_modules(): HasMany
    {
        return $this->hasMany(PlanModule::class);
    }
}
