<?php

namespace Modules\SubscriptionPlan\Http\Controllers;

use Modules\SubscriptionPlan\Models\BillingPackage;
use QCH\Core\Http\Controllers\Controller;

/**
 * Super-admin CRUD of billing v2 PLANS (billing_package reused as a plan:
 * basico..premium). Central catalog — no tenancy. The price of each module
 * inside a plan lives in plan_module_price and is edited via
 * PlanModulePriceController (the matrix editor), NOT here.
 */
class BillingPackageController extends Controller
{
    protected $model = BillingPackage::class;

    protected $keyForPermission = 'billing_package';

    public function includes(): array
    {
        return ['plan_prices', 'plan_prices.billable_module'];
    }

    public function sortableBy(): array
    {
        return ['sort_order', 'name', 'key', 'created_at'];
    }

    public function searchableBy(): array
    {
        return ['name', 'key'];
    }

    public function filterableBy(): array
    {
        return ['is_public', 'is_active'];
    }
}
